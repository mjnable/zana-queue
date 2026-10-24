<?php

declare(strict_types=1);

namespace Zana\Queue;

use Psr\Log\LoggerInterface;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\ScopeMetadata;

/**
 * Processes jobs, each in its own container scope with the attribute
 * "job_id". handle() receives its dependencies from that scope.
 *
 * A job that throws is released with its backoff delay while it has tries
 * left, then moved to the failed jobs.
 */
final class Worker
{
    private bool $stopping = false;

    public function __construct(
        private readonly QueueInterface $queue,
        private readonly ContainerInterface $root,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Process the next available job on $queue.
     *
     * @return bool Whether a job was processed (successfully or not)
     */
    public function runNext(string $queue = 'default'): bool
    {
        $queued = $this->queue->pop($queue);
        if ($queued === null) {
            return false;
        }

        try {
            $this->process($queued);
            $this->queue->ack($queued);
        } catch (\Throwable $error) {
            $this->failed($queued, $error);
        }

        return true;
    }

    /**
     * Process jobs until $maxJobs were processed, the queue is empty (with
     * $stopWhenEmpty) or stop() is called. Sleeps $sleepSeconds when idle.
     *
     * @return int The number of jobs processed
     */
    public function work(string $queue = 'default', ?int $maxJobs = null, bool $stopWhenEmpty = false, int $sleepSeconds = 1): int
    {
        $processed = 0;

        try {
            while (!$this->stopping && ($maxJobs === null || $processed < $maxJobs)) {
                if ($this->runNext($queue)) {
                    $processed++;
                    continue;
                }
                if ($stopWhenEmpty) {
                    break;
                }
                sleep(max(0, $sleepSeconds));
            }
        } finally {
            $this->stopping = false;
        }

        return $processed;
    }

    /**
     * Ask work() to stop after the current job.
     */
    public function stop(): void
    {
        $this->stopping = true;
    }

    /**
     * Run one job in a new scope and dispose the scope. Exceptions from the
     * job propagate; the job is neither acknowledged nor released.
     */
    public function process(QueuedJob $queued): void
    {
        $job = $queued->job();
        $scope = $this->root->createScope(ScopeMetadata::queue($job::class)->withAttribute('job_id', $queued->id));

        try {
            if (!method_exists($job, 'handle')) {
                throw new QueueException('The job ' . $job::class . ' has no handle() method.');
            }
            $scope->call([$job, 'handle']);
        } finally {
            $scope->dispose();
        }
    }

    private function failed(QueuedJob $queued, \Throwable $error): void
    {
        $options = $this->options($queued);
        $context = [
            'job_id' => $queued->id,
            'job' => $this->className($queued),
            'queue' => $queued->queue,
            'attempts' => $queued->attempts,
            'exception' => $error,
        ];

        if ($options !== null && $queued->attempts < $options->tries) {
            $delay = $options->backoffFor($queued->attempts);
            $this->queue->release($queued, $delay);
            $this->logger?->warning('Job {job} failed on attempt {attempts}; retrying in ' . $delay . 's.', $context);

            return;
        }

        $this->queue->fail($queued, $error);
        $this->logger?->error('Job {job} failed after {attempts} attempt(s).', $context);
    }

    /**
     * The job's options, or null when its payload cannot be read (it can never succeed).
     */
    private function options(QueuedJob $queued): ?JobOptions
    {
        try {
            return JobOptions::for($queued->className());
        } catch (QueueException) {
            return null;
        }
    }

    private function className(QueuedJob $queued): string
    {
        try {
            return $queued->className();
        } catch (QueueException) {
            return 'unknown';
        }
    }
}
