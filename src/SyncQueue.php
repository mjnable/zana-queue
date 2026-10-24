<?php

declare(strict_types=1);

namespace Zana\Queue;

use Psr\Log\LoggerInterface;
use Zana\Container\Contracts\ContainerInterface;

/**
 * Processes each job inside push(), in its own scope, once. Failures are
 * rethrown to the caller and nothing is stored, so pop() never returns a job.
 */
final class SyncQueue implements QueueInterface
{
    private readonly Worker $worker;

    private int $sequence = 0;

    public function __construct(ContainerInterface $root, ?LoggerInterface $logger = null)
    {
        $this->worker = new Worker($this, $root, $logger);
    }

    public function push(object $job, ?string $queue = null, int $delay = 0): string
    {
        $id = 'sync-' . ++$this->sequence;
        $this->worker->process(new QueuedJob($id, $queue ?? JobOptions::for($job)->queue, Payload::encode($job), 1, new \DateTimeImmutable()));

        return $id;
    }

    public function pop(string $queue): ?QueuedJob
    {
        return null;
    }

    public function ack(QueuedJob $job): void
    {
    }

    public function release(QueuedJob $job, int $delay = 0): void
    {
    }

    public function fail(QueuedJob $job, \Throwable $error): void
    {
    }

    public function size(string $queue): int
    {
        return 0;
    }

    public function failed(): array
    {
        return [];
    }

    public function retry(string $failedId): string
    {
        throw new QueueException('The sync queue keeps no failed jobs.');
    }
}
