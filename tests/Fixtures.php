<?php

declare(strict_types=1);

namespace Zana\Queue\Tests;

use Psr\Log\AbstractLogger;
use Zana\Container\Contracts\Disposable;
use Zana\Container\ScopeMetadata;
use Zana\Queue\JobOptions;

final class Journal
{
    /** @var list<string> */
    public static array $entries = [];

    /** @var list<JobContext> */
    public static array $contexts = [];

    public static function reset(): void
    {
        self::$entries = [];
        self::$contexts = [];
        FlakyJob::$failuresLeft = 0;
    }
}

/**
 * A scoped service: one per job scope, disposed with it.
 */
final class JobContext implements Disposable
{
    public bool $disposed = false;

    public function __construct(public readonly ScopeMetadata $scope)
    {
    }

    public function dispose(): void
    {
        $this->disposed = true;
        Journal::$entries[] = 'dispose:' . $this->scope->attribute('job_id');
    }
}

final class RecordJob
{
    public function __construct(public readonly string $name)
    {
    }

    public function handle(JobContext $context): void
    {
        Journal::$entries[] = "handle:{$this->name}";
        Journal::$contexts[] = $context;
    }
}

#[JobOptions(queue: 'mail', tries: 3, backoff: [10, 60])]
final class FlakyJob
{
    public static int $failuresLeft = 0;

    public function handle(): void
    {
        if (self::$failuresLeft-- > 0) {
            throw new \RuntimeException('mail server unavailable');
        }
        Journal::$entries[] = 'flaky:done';
    }
}

final class FailingJob
{
    public function handle(): void
    {
        throw new \DomainException('patient not found');
    }
}

final class NoHandleJob
{
}

enum Priority: string
{
    case High = 'high';
    case Low = 'low';
}

final class PatientRef
{
    public function __construct(public readonly string $id, public readonly ?Clinic $clinic = null)
    {
    }
}

final class Clinic
{
    public function __construct(public readonly string $name)
    {
    }
}

final class WelcomeJob
{
    public function __construct(
        public readonly PatientRef $patient,
        public readonly Priority $priority,
        public readonly \DateTimeImmutable $requestedAt,
    ) {
    }

    public function handle(): void
    {
        Journal::$entries[] = "welcome:{$this->patient->id}:{$this->priority->value}:" . ($this->patient->clinic->name ?? '-');
    }
}

/**
 * Holds an object in an untyped property, which a payload cannot read back.
 */
final class LooseJob
{
    public function __construct(public mixed $data)
    {
    }

    public function handle(): void
    {
    }
}

final class FakeClock
{
    public int $now = 1_800_000_000;

    /**
     * @return \Closure(): int
     */
    public function closure(): \Closure
    {
        return fn (): int => $this->now;
    }
}

final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) $message, $context];
    }
}
