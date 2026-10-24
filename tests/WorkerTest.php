<?php

declare(strict_types=1);

namespace Zana\Queue\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\ServiceCollection;
use Zana\Queue\Dispatcher;
use Zana\Queue\InMemoryQueue;
use Zana\Queue\JobOptions;
use Zana\Queue\Payload;
use Zana\Queue\QueueException;
use Zana\Queue\SyncQueue;
use Zana\Queue\Worker;

final class WorkerTest extends TestCase
{
    private FakeClock $clock;

    private InMemoryQueue $queue;

    private MemoryLogger $logger;

    private Worker $worker;

    private ContainerInterface $root;

    protected function setUp(): void
    {
        Journal::reset();
        $this->clock = new FakeClock();
        $this->queue = new InMemoryQueue($this->clock->closure());
        $this->logger = new MemoryLogger();
        $this->root = new ServiceCollection()->scoped(JobContext::class)->build();
        $this->worker = new Worker($this->queue, $this->root, $this->logger);
    }

    public function testEachJobRunsInItsOwnScope(): void
    {
        $first = $this->queue->push(new RecordJob('a'));
        $second = $this->queue->push(new RecordJob('b'));

        $this->assertTrue($this->worker->runNext());
        $this->assertTrue($this->worker->runNext());
        $this->assertFalse($this->worker->runNext(), 'nothing left');

        $this->assertSame(['handle:a', "dispose:{$first}", 'handle:b', "dispose:{$second}"], Journal::$entries);
        [$a, $b] = Journal::$contexts;
        $this->assertNotSame($a, $b, 'scoped services differ per job');
        $this->assertTrue($a->disposed && $b->disposed);
        $this->assertSame('queue', $a->scope->type);
        $this->assertSame(RecordJob::class, $a->scope->name);
        $this->assertSame($first, $a->scope->attribute('job_id'));
        $this->assertSame($this->root->metadata()->id, $a->scope->parentId);
        $this->assertSame(0, $this->queue->size('default'), 'successful jobs are acknowledged');
    }

    public function testRetriesFollowTheBackoffThenFail(): void
    {
        FlakyJob::$failuresLeft = 5;
        $this->queue->push(new FlakyJob());

        $this->assertTrue($this->worker->runNext('mail'));
        $this->assertFalse($this->worker->runNext('mail'), 'released for 10 seconds');
        $this->clock->now += 10;
        $this->assertTrue($this->worker->runNext('mail'));
        $this->clock->now += 59;
        $this->assertFalse($this->worker->runNext('mail'), 'released for 60 seconds');
        $this->clock->now += 1;
        $this->assertTrue($this->worker->runNext('mail'));

        $this->assertSame(0, $this->queue->size('mail'));
        $failed = $this->queue->failed();
        $this->assertCount(1, $failed);
        $this->assertSame('RuntimeException: mail server unavailable', $failed[0]->error);

        $this->assertSame(['warning', 'warning', 'error'], array_column($this->logger->records, 0));
        $this->assertStringContainsString('retrying in 10s', $this->logger->records[0][1]);
        $this->assertStringContainsString('retrying in 60s', $this->logger->records[1][1]);
        $this->assertSame(3, $this->logger->records[2][2]['attempts']);
        $this->assertSame(FlakyJob::class, $this->logger->records[2][2]['job']);
        $this->assertInstanceOf(\RuntimeException::class, $this->logger->records[2][2]['exception']);
    }

    public function testAJobThatRecoversIsAcknowledged(): void
    {
        FlakyJob::$failuresLeft = 1;
        $this->queue->push(new FlakyJob());

        $this->worker->runNext('mail');
        $this->clock->now += 10;
        $this->worker->runNext('mail');

        $this->assertSame(['flaky:done'], Journal::$entries);
        $this->assertSame(0, $this->queue->size('mail'));
        $this->assertSame([], $this->queue->failed());
    }

    public function testJobsThatCannotRunFailAtOnce(): void
    {
        $this->queue->push(new LooseJob(new PatientRef('p1')));
        $this->queue->push(new NoHandleJob());
        $this->worker->work(stopWhenEmpty: true);

        $errors = array_map(static fn ($job): string => $job->error, $this->queue->failed());
        $this->assertCount(2, $errors);
        $this->assertStringContainsString('holds objects of classes its properties do not declare', $errors[0]);
        $this->assertStringContainsString('has no handle() method', $errors[1]);
    }

    public function testTypedPropertiesSurviveTheRoundTrip(): void
    {
        $this->queue->push(new WelcomeJob(new PatientRef('p7', new Clinic('North')), Priority::High, new \DateTimeImmutable()));
        $this->worker->runNext();

        $this->assertSame(['welcome:p7:high:North'], Journal::$entries);
        $this->assertEqualsCanonicalizing(
            [WelcomeJob::class, PatientRef::class, Clinic::class, Priority::class, \DateTimeImmutable::class],
            Payload::allowedClasses(WelcomeJob::class),
        );
    }

    public function testWorkHonoursMaxJobsAndStopWhenEmpty(): void
    {
        foreach (['a', 'b', 'c'] as $name) {
            $this->queue->push(new RecordJob($name));
        }

        $this->assertSame(2, $this->worker->work(maxJobs: 2));
        $this->assertSame(1, $this->worker->work(stopWhenEmpty: true));
        $this->assertSame(0, $this->worker->work(stopWhenEmpty: true));
    }

    public function testTheSyncQueueRunsJobsImmediately(): void
    {
        $sync = new SyncQueue($this->root);
        $sync->push(new RecordJob('now'));

        $this->assertSame(['handle:now', 'dispose:sync-1'], Journal::$entries);
        $this->assertNull($sync->pop('default'));
        $this->assertSame(0, $sync->size('default'));

        $this->expectException(\DomainException::class);
        $sync->push(new FailingJob());
    }

    public function testTheDispatcherUsesTheJobOptionsQueue(): void
    {
        $dispatcher = new Dispatcher($this->queue);
        $dispatcher->dispatch(new FlakyJob());
        $dispatcher->dispatch(new FlakyJob(), 'urgent');
        $dispatcher->dispatch(new RecordJob('later'), delay: 5);

        $this->assertSame(1, $this->queue->size('mail'));
        $this->assertSame(1, $this->queue->size('urgent'));
        $this->assertNull($this->queue->pop('default'));
        $this->clock->now += 5;
        $this->assertNotNull($this->queue->pop('default'));
    }

    public function testJobOptions(): void
    {
        $options = new JobOptions(backoff: [10, 60]);
        $this->assertSame([10, 60, 60, 60], [$options->backoffFor(1), $options->backoffFor(2), $options->backoffFor(3), $options->backoffFor(9)]);
        $this->assertSame(0, new JobOptions()->backoffFor(1));
        $this->assertEquals(new JobOptions('mail', 3, [10, 60]), JobOptions::for(FlakyJob::class));
        $this->assertEquals(new JobOptions(), JobOptions::for(new RecordJob('x')));

        foreach ([static fn () => new JobOptions(tries: 0), static fn () => new JobOptions(''), static fn () => new JobOptions(backoff: [-1])] as $invalid) {
            try {
                $invalid();
                $this->fail('Invalid options must be rejected.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPayloadsRejectWhatCannotBeQueuedOrRead(): void
    {
        foreach ([static fn () => Payload::encode(static fn () => null), static fn () => Payload::encode(new class () {})] as $invalid) {
            try {
                $invalid();
                $this->fail('Closures and anonymous classes cannot be queued.');
            } catch (QueueException) {
                $this->addToAssertionCount(1);
            }
        }

        $tampered = serialize(new RecordJob('x'));
        $tampered = str_replace('s:1:"x"', 'O:8:"stdClass":0:{}', $tampered);
        foreach (['not a payload', 'O:11:"MissingJob1":0:{}', $tampered] as $payload) {
            try {
                Payload::decode($payload);
                $this->fail("The payload {$payload} must be rejected.");
            } catch (QueueException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
