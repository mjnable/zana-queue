<?php

declare(strict_types=1);

namespace Zana\Queue;

/**
 * A job that used all its tries. error is "<exception class>: <message>".
 */
final readonly class FailedJob
{
    public function __construct(
        public string $id,
        public string $queue,
        public string $payload,
        public string $error,
        public \DateTimeImmutable $failedAt,
    ) {
    }

    /**
     * The job class, or null when the payload names no known class.
     */
    public function className(): ?string
    {
        try {
            return Payload::className($this->payload);
        } catch (QueueException) {
            return null;
        }
    }

    public static function describe(\Throwable $error): string
    {
        return $error::class . ': ' . $error->getMessage();
    }
}
