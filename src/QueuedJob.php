<?php

declare(strict_types=1);

namespace Zana\Queue;

/**
 * A job taken from a queue. attempts counts this attempt (it is incremented
 * when the job is popped).
 */
final readonly class QueuedJob
{
    public function __construct(
        public string $id,
        public string $queue,
        public string $payload,
        public int $attempts,
        public \DateTimeImmutable $availableAt,
    ) {
    }

    /**
     * @return class-string
     */
    public function className(): string
    {
        return Payload::className($this->payload);
    }

    public function job(): object
    {
        return Payload::decode($this->payload);
    }
}
