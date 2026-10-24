<?php

declare(strict_types=1);

namespace Zana\Queue\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zana\Database\ConnectionFactory;
use Zana\Database\ConnectionInterface;
use Zana\Queue\DatabaseQueue;
use Zana\Queue\InMemoryQueue;
use Zana\Queue\QueueException;
use Zana\Queue\QueueInterface;
use Zana\Queue\QueueTablesMigration;

final class QueueDriverTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        yield 'memory' => ['memory'];
        yield 'database' => ['database'];
    }

    /**
     * @return array{QueueInterface, FakeClock}
     */
    private function queue(string $driver): array
    {
        $clock = new FakeClock();

        return [$driver === 'memory' ? new InMemoryQueue($clock->closure()) : new DatabaseQueue(self::connection(), 90, $clock->closure()), $clock];
    }

    private static function connection(): ConnectionInterface
    {
        $db = ConnectionFactory::create(['driver' => 'sqlite', 'database' => ':memory:']);
        new QueueTablesMigration()->up($db->schema());

        return $db;
    }

    #[DataProvider('drivers')]
    public function testPushPopAndAckOldestFirst(string $driver): void
    {
        [$queue] = $this->queue($driver);
        $first = $queue->push(new RecordJob('a'));
        $queue->push(new RecordJob('b'));
        $queue->push(new RecordJob('elsewhere'), 'other');

        $this->assertSame(2, $queue->size('default'));
        $this->assertSame(1, $queue->size('other'));

        $job = $queue->pop('default');
        $this->assertNotNull($job);
        $this->assertSame($first, $job->id);
        $this->assertSame('default', $job->queue);
        $this->assertSame(1, $job->attempts);
        $this->assertSame(RecordJob::class, $job->className());
        $this->assertEquals(new RecordJob('a'), $job->job());
        $this->assertSame(2, $queue->size('default'), 'reserved jobs still count');

        $second = $queue->pop('default');
        $this->assertNotNull($second);
        $this->assertSame('b', $this->jobName($second->job()));
        $this->assertNull($queue->pop('default'), 'reserved jobs are not popped twice');

        $queue->ack($job);
        $queue->ack($second);
        $this->assertSame(0, $queue->size('default'));
        $this->assertNull($queue->pop('missing'));
    }

    #[DataProvider('drivers')]
    public function testTheQueueDefaultsToTheJobOptions(string $driver): void
    {
        [$queue] = $this->queue($driver);
        $queue->push(new FlakyJob());

        $this->assertSame(1, $queue->size('mail'));
        $this->assertSame(0, $queue->size('default'));
    }

    #[DataProvider('drivers')]
    public function testDelaysAndRelease(string $driver): void
    {
        [$queue, $clock] = $this->queue($driver);
        $queue->push(new RecordJob('later'), delay: 30);
        $queue->push(new RecordJob('now'));

        $job = $queue->pop('default');
        $this->assertNotNull($job);
        $this->assertSame('now', $this->jobName($job->job()));
        $this->assertNull($queue->pop('default'), 'the delayed job is not available yet');

        $queue->release($job, 60);
        $clock->now += 30;
        $later = $queue->pop('default');
        $this->assertNotNull($later);
        $this->assertSame('later', $this->jobName($later->job()));
        $waiting = $queue->pop('default');
        $this->assertNull($waiting, 'the released job waits 60 seconds');

        $clock->now += 30;
        $again = $queue->pop('default');
        $this->assertNotNull($again);
        $this->assertSame('now', $this->jobName($again->job()));
        $this->assertSame(2, $again->attempts, 'attempts are kept across releases');
    }

    #[DataProvider('drivers')]
    public function testFailureStorageAndRetry(string $driver): void
    {
        [$queue, $clock] = $this->queue($driver);
        $queue->push(new RecordJob('broken'), 'reports');
        $job = $queue->pop('reports');
        $this->assertNotNull($job);

        $queue->fail($job, new \DomainException('no such patient'));

        $this->assertSame(0, $queue->size('reports'));
        $failed = $queue->failed();
        $this->assertCount(1, $failed);
        $this->assertSame('reports', $failed[0]->queue);
        $this->assertSame(RecordJob::class, $failed[0]->className());
        $this->assertSame('DomainException: no such patient', $failed[0]->error);
        $this->assertSame($clock->now, $failed[0]->failedAt->getTimestamp());

        $queue->retry($failed[0]->id);
        $this->assertSame([], $queue->failed());
        $retried = $queue->pop('reports');
        $this->assertNotNull($retried);
        $this->assertSame(1, $retried->attempts, 'attempts start again');
        $this->assertSame('broken', $this->jobName($retried->job()));

        $this->expectException(QueueException::class);
        $queue->retry('404');
    }

    public function testAbandonedDatabaseReservationsAreTakenAgain(): void
    {
        $clock = new FakeClock();
        $db = self::connection();
        $workerA = new DatabaseQueue($db, 90, $clock->closure());
        $workerB = new DatabaseQueue($db, 90, $clock->closure());

        $workerA->push(new RecordJob('long'));
        $this->assertNotNull($workerA->pop('default'));
        $reserved = $workerB->pop('default');
        $this->assertNull($reserved, 'another worker does not take a reserved job');

        $clock->now += 90;
        $taken = $workerB->pop('default');
        $this->assertNotNull($taken, 'the reservation expired');
        $this->assertSame(2, $taken->attempts);
    }

    public function testTheMigrationCreatesAndDropsTheTables(): void
    {
        $db = self::connection();
        $this->assertTrue($db->schema()->hasTable('zana_jobs'));
        $this->assertTrue($db->schema()->hasTable('zana_failed_jobs'));

        new QueueTablesMigration()->down($db->schema());
        $this->assertFalse($db->schema()->hasTable('zana_jobs'));
        $this->assertFalse($db->schema()->hasTable('zana_failed_jobs'));
    }

    private function jobName(object $job): string
    {
        $this->assertInstanceOf(RecordJob::class, $job);

        return $job->name;
    }
}
