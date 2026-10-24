<?php

declare(strict_types=1);

namespace Zana\Queue;

use Closure;

/**
 * Keeps jobs in memory, for tests and single processes.
 */
final class InMemoryQueue implements QueueInterface
{
    /** @var array<array-key, array{queue: string, payload: string, attempts: int, availableAt: int, reserved: bool, order: int}> */
    private array $jobs = [];

    /** @var array<string, FailedJob> */
    private array $failed = [];

    private int $sequence = 0;

    /** @var Closure(): int */
    private readonly Closure $now;

    /**
     * @param (Closure(): int)|null $clock Returns the current Unix time; defaults to time()
     */
    public function __construct(?Closure $clock = null)
    {
        $this->now = $clock ?? static fn (): int => time();
    }

    public function push(object $job, ?string $queue = null, int $delay = 0): string
    {
        return $this->pushRaw(Payload::encode($job), $queue ?? JobOptions::for($job)->queue, $delay);
    }

    public function pop(string $queue): ?QueuedJob
    {
        $now = ($this->now)();
        $next = null;
        foreach ($this->jobs as $id => $job) {
            if ($job['queue'] !== $queue || $job['reserved'] || $job['availableAt'] > $now) {
                continue;
            }
            if ($next === null || [$job['availableAt'], $job['order']] < [$this->jobs[$next]['availableAt'], $this->jobs[$next]['order']]) {
                $next = $id;
            }
        }

        if ($next === null) {
            return null;
        }

        $job = $this->jobs[$next];
        $job['reserved'] = true;
        $job['attempts']++;
        $this->jobs[$next] = $job;

        return new QueuedJob((string) $next, $queue, $job['payload'], $job['attempts'], new \DateTimeImmutable('@' . $job['availableAt']));
    }

    public function ack(QueuedJob $job): void
    {
        unset($this->jobs[$job->id]);
    }

    public function release(QueuedJob $job, int $delay = 0): void
    {
        $stored = $this->jobs[$job->id] ?? null;
        if ($stored !== null) {
            $stored['reserved'] = false;
            $stored['availableAt'] = ($this->now)() + max(0, $delay);
            $stored['order'] = ++$this->sequence;
            $this->jobs[$job->id] = $stored;
        }
    }

    public function fail(QueuedJob $job, \Throwable $error): void
    {
        unset($this->jobs[$job->id]);
        $id = 'f' . ++$this->sequence;
        $this->failed[$id] = new FailedJob($id, $job->queue, $job->payload, FailedJob::describe($error), new \DateTimeImmutable('@' . ($this->now)()));
    }

    public function size(string $queue): int
    {
        return count(array_filter($this->jobs, static fn (array $job): bool => $job['queue'] === $queue));
    }

    public function failed(): array
    {
        return array_values($this->failed);
    }

    public function retry(string $failedId): string
    {
        $failed = $this->failed[$failedId] ?? throw new QueueException("There is no failed job with id \"{$failedId}\".");
        unset($this->failed[$failedId]);

        return $this->pushRaw($failed->payload, $failed->queue, 0);
    }

    private function pushRaw(string $payload, string $queue, int $delay): string
    {
        $id = (string) ++$this->sequence;
        $this->jobs[$id] = ['queue' => $queue, 'payload' => $payload, 'attempts' => 0, 'availableAt' => ($this->now)() + max(0, $delay), 'reserved' => false, 'order' => $this->sequence];

        return $id;
    }
}
