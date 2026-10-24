<?php

declare(strict_types=1);

namespace Zana\Queue;

/**
 * How a job class is queued and retried.
 *
 *     #[JobOptions(queue: 'mail', tries: 3, backoff: [10, 60])]
 *
 * The delay before attempt n+1 is backoff[n-1]; the last value repeats and an
 * empty list means no delay.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class JobOptions
{
    /**
     * @param list<int> $backoff Seconds to wait before each retry
     */
    public function __construct(
        public string $queue = 'default',
        public int $tries = 1,
        public array $backoff = [],
    ) {
        if ($queue === '') {
            throw new \InvalidArgumentException('A job queue name cannot be empty.');
        }
        if ($tries < 1) {
            throw new \InvalidArgumentException("A job needs at least one try; got {$tries}.");
        }
        foreach ($backoff as $delay) {
            if ($delay < 0) {
                throw new \InvalidArgumentException("Backoff delays cannot be negative; got {$delay}.");
            }
        }
    }

    /**
     * The options declared on a job class, or the defaults.
     *
     * @param object|class-string $job
     */
    public static function for(object|string $job): self
    {
        $attributes = new \ReflectionClass($job)->getAttributes(self::class);

        return $attributes === [] ? new self() : $attributes[0]->newInstance();
    }

    /**
     * The delay in seconds before retrying a job that failed on attempt $attempt (1-based).
     */
    public function backoffFor(int $attempt): int
    {
        if ($this->backoff === []) {
            return 0;
        }

        return $this->backoff[min(max($attempt, 1), count($this->backoff)) - 1];
    }
}
