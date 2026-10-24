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
 */
final class DatabaseQueue implements QueueInterface
{
    public const string JOBS_TABLE = 'zana_jobs';

    public const string FAILED_TABLE = 'zana_failed_jobs';

    /** @var Closure(): int */
    private readonly Closure $now;

    /**
     * @param (Closure(): int)|null $clock Returns the current Unix time; defaults to time()
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly int $retryAfter = 90,
        ?Closure $clock = null,
    ) {
        $this->now = $clock ?? static fn (): int => time();
    }

    public function push(object $job, ?string $queue = null, int $delay = 0): string
    {
        return $this->pushRaw(Payload::encode($job), $queue ?? JobOptions::for($job)->queue, $delay);
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
                    return new QueuedJob(
                        (string) $row['id'],
                        $queue,
                        (string) $row['payload'],
                        (int) $row['attempts'] + 1,
                        new \DateTimeImmutable('@' . (int) $row['available_at']),
                    );
                }
            }

            return null;
        });
    }

    public function ack(QueuedJob $job): void
    {
        $this->connection->table(self::JOBS_TABLE)->where('id', $job->id)->delete();
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

            return $this->pushRaw((string) $row['payload'], (string) $row['queue'], 0, $db);
        });
    }

    private function pushRaw(string $payload, string $queue, int $delay, ?ConnectionInterface $db = null): string
    {
        $now = ($this->now)();

        return ($db ?? $this->connection)->insert(self::JOBS_TABLE, [
            'queue' => $queue,
            'payload' => $payload,
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now + max(0, $delay),
            'created_at' => $now,
        ]);
    }
}
