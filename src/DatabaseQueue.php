<?php

declare(strict_types=1);

namespace Zana\Queue;

use Closure;
use Zana\Database\ConnectionInterface;

/**
 * Stores jobs in zana_jobs and failures in zana_failed_jobs (see
 * QueueTablesMigration).
 *
 * pop() reserves a row with a conditional update inside a transaction, so
 * two workers never take the same job. A reservation older than $retryAfter
 * seconds is treated as abandoned (its worker died) and the job is taken again.
 *
 * Copies of signed jobs are refused (2.34) once QueueReplayMigration has run:
 * each job is signed with a nonce and the time it was issued; the nonce is
 * unique among the waiting jobs, so a copy cannot be added beside its job,
 * and recorded in zana_jobs_done when the job ran, so a copy added later is
 * refused. A job issued longer ago than the replay window (30 days by
 * default, queue.replay_window), whose record may be gone, is refused too, as
 * is one signed before 2.34 without a nonce. Each refused job goes to the
 * failed jobs with the reason; queue:retry signs it again.
 */
final class DatabaseQueue implements QueueInterface
{
    public const string JOBS_TABLE = 'zana_jobs';

    public const string FAILED_TABLE = 'zana_failed_jobs';

    /** The nonces of the jobs that ran (2.34). */
    public const string DONE_TABLE = 'zana_jobs_done';

    /** 30 days. */
    public const int REPLAY_WINDOW = 2_592_000;

    /** @var Closure(): int */
    private readonly Closure $now;

    private ?bool $guarded = null;

    /**
     * @param (Closure(): int)|null $clock Returns the current Unix time; defaults to time()
     * @param int $replayWindow Seconds a job's run is remembered, and the oldest a job may be when it runs (2.34)
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly int $retryAfter = 90,
        ?Closure $clock = null,
        private readonly ?PayloadKey $key = null,
        private readonly int $replayWindow = self::REPLAY_WINDOW,
    ) {
        $this->now = $clock ?? static fn (): int => time();
    }

    /**
     * Whether copies of jobs are refused (2.34): jobs are signed, and QueueReplayMigration has run.
     */
    public function refusesCopies(): bool
    {
        return $this->guarded ??= $this->key !== null && $this->key->signs()
            && $this->connection->schema()->hasTable(self::DONE_TABLE)
            && $this->connection->schema()->hasColumn(self::JOBS_TABLE, 'nonce');
    }

    public function push(object $job, ?string $queue = null, int $delay = 0): string
    {
        $queue ??= JobOptions::for($job)->queue;
        if (!$this->refusesCopies()) {
            return $this->pushRaw(Payload::encode($job, $this->key), $queue, $delay);
        }
        $nonce = bin2hex(random_bytes(16));

        return $this->pushRaw(Payload::encode($job, $this->key, $nonce, ($this->now)()), $queue, $delay, nonce: $nonce);
    }

    public function pop(string $queue): ?QueuedJob
    {
        $grammar = $this->connection->grammar();
        $table = $grammar->wrap(self::JOBS_TABLE);
        $available = "({$grammar->wrap('reserved_at')} IS NULL OR {$grammar->wrap('reserved_at')} <= ?)";

        return $this->connection->transaction(function (ConnectionInterface $db) use ($table, $available, $queue, $grammar): ?QueuedJob {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $now = ($this->now)();
                $row = $db->first(
                    "SELECT {$grammar->wrap('id')}, {$grammar->wrap('payload')}, {$grammar->wrap('attempts')}, {$grammar->wrap('available_at')} FROM {$table}"
                    . " WHERE {$grammar->wrap('queue')} = ? AND {$grammar->wrap('available_at')} <= ? AND {$available}"
                    . " ORDER BY {$grammar->wrap('available_at')}, {$grammar->wrap('id')} LIMIT 1",
                    [$queue, $now, $now - $this->retryAfter],
                );
                if ($row === null) {
                    return null;
                }

                $reserved = $db->execute(
                    "UPDATE {$table} SET {$grammar->wrap('reserved_at')} = ?, {$grammar->wrap('attempts')} = {$grammar->wrap('attempts')} + 1"
                    . " WHERE {$grammar->wrap('id')} = ? AND {$available}",
                    [$now, $row['id'], $now - $this->retryAfter],
                );
                if ($reserved === 1) {
                    $job = new QueuedJob(
                        (string) $row['id'],
                        $queue,
                        (string) $row['payload'],
                        (int) $row['attempts'] + 1,
                        new \DateTimeImmutable('@' . (int) $row['available_at']),
                        $this->key,
                    );
                    $refusal = $this->refusesCopies() ? $this->refusal($db, $job->payload, $now) : null;
                    if ($refusal === null) {
                        return $job;
                    }
                    $this->moveToFailed($db, $job, $refusal);   // 2.34: a copy, or too old to tell; the next job is looked at
                }
            }

            return null;
        });
    }

    public function ack(QueuedJob $job): void
    {
        if (!$this->refusesCopies()) {
            $this->connection->table(self::JOBS_TABLE)->where('id', $job->id)->delete();

            return;
        }
        $this->connection->transaction(function (ConnectionInterface $db) use ($job): void {
            $nonce = $this->nonce($job->payload);
            $now = ($this->now)();
            if ($nonce !== null) {
                // The run is remembered with the job's removal, so a copy added after it is refused.
                $db->table(self::DONE_TABLE)->upsert(['nonce' => $nonce, 'done_at' => $now], uniqueBy: ['nonce'], update: []);
            }
            $db->table(self::JOBS_TABLE)->where('id', $job->id)->delete();
            $db->table(self::DONE_TABLE)->where('done_at', '<', $now - $this->replayWindow)->delete();
        });
    }

    /**
     * Why a job must not run (2.34), or null when it may: its nonce was recorded, it is older than the window, or
     * it carries no nonce. A payload whose signature is wrong is left to the worker, which fails it as unreadable.
     */
    private function refusal(ConnectionInterface $db, string $payload, int $now): ?string
    {
        try {
            $envelope = $this->key?->envelope($payload);
        } catch (QueueException) {
            return null;
        }
        if ($envelope === null) {
            return 'Refused: the job was signed without a nonce, before 2.34, so a copy of it cannot be told from it. queue:retry signs it again.';
        }
        if ($db->table(self::DONE_TABLE)->where('nonce', $envelope['nonce'])->exists()) {
            return 'Refused: this job already ran (its nonce is recorded), so this row is a copy of it.';
        }
        if ($envelope['issuedAt'] < $now - $this->replayWindow) {
            return 'Refused: the job was issued longer ago than queue.replay_window, so a copy of it can no longer be told from it. queue:retry signs it again.';
        }

        return null;
    }

    /**
     * The nonce of a payload whose signature holds, or null.
     */
    private function nonce(string $payload): ?string
    {
        try {
            return $this->key?->envelope($payload)['nonce'] ?? null;
        } catch (QueueException) {
            return null;
        }
    }

    private function moveToFailed(ConnectionInterface $db, QueuedJob $job, string $error): void
    {
        $db->table(self::JOBS_TABLE)->where('id', $job->id)->delete();
        $db->insert(self::FAILED_TABLE, [
            'queue' => $job->queue,
            'payload' => $job->payload,
            'error' => $error,
            'failed_at' => ($this->now)(),
        ]);
    }

    public function release(QueuedJob $job, int $delay = 0): void
    {
        $this->connection->table(self::JOBS_TABLE)->where('id', $job->id)->update([
            'reserved_at' => null,
            'available_at' => ($this->now)() + max(0, $delay),
        ]);
    }

    public function fail(QueuedJob $job, \Throwable $error): void
    {
        $this->connection->transaction(function (ConnectionInterface $db) use ($job, $error): void {
            $db->table(self::JOBS_TABLE)->where('id', $job->id)->delete();
            $db->insert(self::FAILED_TABLE, [
                'queue' => $job->queue,
                'payload' => $job->payload,
                'error' => FailedJob::describe($error),
                'failed_at' => ($this->now)(),
            ]);
        });
    }

    public function size(string $queue): int
    {
        return $this->connection->table(self::JOBS_TABLE)->where('queue', $queue)->count();
    }

    public function failed(): array
    {
        return array_map(
            static fn (array $row): FailedJob => new FailedJob(
                (string) $row['id'],
                (string) $row['queue'],
                (string) $row['payload'],
                (string) $row['error'],
                new \DateTimeImmutable('@' . (int) $row['failed_at']),
            ),
            $this->connection->table(self::FAILED_TABLE)->orderBy('id')->get(),
        );
    }

    public function retry(string $failedId): string
    {
        return $this->connection->transaction(function (ConnectionInterface $db) use ($failedId): string {
            $row = $db->table(self::FAILED_TABLE)->where('id', $failedId)->first()
                ?? throw new QueueException("There is no failed job with id \"{$failedId}\".");
            $db->table(self::FAILED_TABLE)->where('id', $failedId)->delete();
            $payload = (string) $row['payload'];
            if (!$this->refusesCopies() || $this->key === null) {
                return $this->pushRaw($payload, (string) $row['queue'], 0, $db);
            }
            // 2.34: signed again, with a fresh nonce and issue time, once its signature is checked: a retry is the
            // operator's decision, so a job refused as old or signed without a nonce may run.
            $nonce = bin2hex(random_bytes(16));

            return $this->pushRaw($this->key->seal($this->key->open($payload), $nonce, ($this->now)()), (string) $row['queue'], 0, $db, $nonce);
        });
    }

    private function pushRaw(string $payload, string $queue, int $delay, ?ConnectionInterface $db = null, ?string $nonce = null): string
    {
        $now = ($this->now)();
        $row = [
            'queue' => $queue,
            'payload' => $payload,
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now + max(0, $delay),
            'created_at' => $now,
        ];

        return ($db ?? $this->connection)->insert(self::JOBS_TABLE, $nonce === null ? $row : [...$row, 'nonce' => $nonce]);
    }
}
