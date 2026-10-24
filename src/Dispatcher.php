<?php

declare(strict_types=1);

namespace Zana\Queue;

/**
 * Sends jobs to the default queue driver.
 */
final class Dispatcher
{
    public function __construct(private readonly QueueInterface $queue)
    {
    }

    /**
     * @param string|null $queue Defaults to the job's JobOptions queue
     *
     * @return string The job id
     */
    public function dispatch(object $job, ?string $queue = null, int $delay = 0): string
    {
        return $this->queue->push($job, $queue ?? JobOptions::for($job)->queue, $delay);
    }
}
