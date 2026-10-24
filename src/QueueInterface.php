<?php

declare(strict_types=1);

namespace Zana\Queue;

interface QueueInterface
{
    /**
     * Queue a job; it becomes available after $delay seconds.
     *
     * @param string|null $queue Defaults to the job's JobOptions queue
     *
     * @return string The job id
     */
    public function push(object $job, ?string $queue = null, int $delay = 0): string;

    /**
     * Reserve the next available job on $queue, oldest first.
     */
    public function pop(string $queue): ?QueuedJob;

    /**
     * Remove a reserved job that succeeded.
     */
    public function ack(QueuedJob $job): void;

    /**
     * Put a reserved job back, available again after $delay seconds.
     */
    public function release(QueuedJob $job, int $delay = 0): void;

    /**
     * Move a reserved job to the failed jobs.
     */
    public function fail(QueuedJob $job, \Throwable $error): void;

    /**
     * The number of jobs on $queue that are waiting or reserved.
     */
    public function size(string $queue): int;

    /**
     * @return list<FailedJob> Oldest first
     */
    public function failed(): array;

    /**
     * Queue a failed job again, with its attempts reset.
     *
     * @return string The new job id
     */
    public function retry(string $failedId): string;
}
