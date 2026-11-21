<?php

declare(strict_types=1);

namespace Zana\Queue\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zana\Database\ConnectionFactory;
use Zana\Queue\DatabaseQueue;
use Zana\Queue\Payload;
use Zana\Queue\PayloadKey;
use Zana\Queue\QueueReplayMigration;
use Zana\Queue\QueueTablesMigration;

/**
 * The database queue on every database available here. serialize() writes
 * NUL bytes for protected and private properties, and PostgreSQL silently
 * truncates text at the first one: payloads are stored as base64.
 */
final class PlatformTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function drivers(): array
    {
        return Databases::names();
    }

    #[DataProvider('drivers')]
    public function testJobsWithPrivateAndProtectedPropertiesSurviveTheDatabase(string $driver): void
    {
        $settings = Databases::settings($driver);
        if (is_string($settings)) {
            $this->markTestSkipped($settings);
        }
        $db = ConnectionFactory::create($settings);
        new QueueTablesMigration()->down($db->schema());
        new QueueTablesMigration()->up($db->schema());
        $queue = new DatabaseQueue($db);
        $job = new EncapsulatedJob('ada@example.com', 3);

        $queue->push($job);
        $popped = $queue->pop('default');
        $this->assertNotNull($popped);
        $this->assertEquals($job, $popped->job());

        $queue->fail($popped, new \RuntimeException('SMTP timeout'));
        [$failed] = $queue->failed();
        $this->assertSame(EncapsulatedJob::class, $failed->className());
        $queue->retry($failed->id);
        $again = $queue->pop('default');
        $this->assertNotNull($again);
        $this->assertEquals($job, $again->job(), 'a retried job reads back too');
    }

    /**
     * 2.34: the replay migration changes a table that exists, which each database does its own way; and the unique
     * nonce must let many jobs without one (NULL) through, which they all do.
     */
    #[DataProvider('drivers')]
    public function testCopiesAreRefusedOnEveryDatabase(string $driver): void
    {
        $settings = Databases::settings($driver);
        if (is_string($settings)) {
            $this->markTestSkipped($settings);
        }
        $db = ConnectionFactory::create($settings);
        $db->schema()->dropIfExists(DatabaseQueue::DONE_TABLE);
        new QueueTablesMigration()->down($db->schema());
        new QueueTablesMigration()->up($db->schema());
        $db->insert(DatabaseQueue::JOBS_TABLE, ['queue' => 'default', 'payload' => 'a', 'attempts' => 0, 'available_at' => 0, 'created_at' => 0]);   // a job from before
        new QueueReplayMigration()->up($db->schema());
        $db->insert(DatabaseQueue::JOBS_TABLE, ['queue' => 'other', 'payload' => 'b', 'attempts' => 0, 'available_at' => 0, 'created_at' => 0]);   // two without a nonce
        $queue = new DatabaseQueue($db, key: new PayloadKey(str_repeat('k', 40)));
        $this->assertTrue($queue->refusesCopies());

        $queue->push(new RecordJob('pay'), 'pay');
        $row = $db->table(DatabaseQueue::JOBS_TABLE)->where('queue', 'pay')->first();
        $this->assertIsArray($row);
        try {
            $db->insert(DatabaseQueue::JOBS_TABLE, ['queue' => 'pay', 'payload' => $row['payload'], 'attempts' => 0, 'available_at' => 0, 'created_at' => 0, 'nonce' => $row['nonce']]);
            $this->fail('The nonce is not unique.');
        } catch (\Zana\Database\DatabaseException) {
            $this->addToAssertionCount(1);
        }
        $popped = $queue->pop('pay');
        $this->assertNotNull($popped);
        $queue->ack($popped);

        $db->insert(DatabaseQueue::JOBS_TABLE, ['queue' => 'pay', 'payload' => $row['payload'], 'attempts' => 0, 'available_at' => 0, 'created_at' => 0]);
        $this->assertNull($queue->pop('pay'), 'the copy is refused');
        $this->assertStringContainsString('already ran', $queue->failed()[0]->error);

        new QueueReplayMigration()->down($db->schema());
        $this->assertFalse($db->schema()->hasTable(DatabaseQueue::DONE_TABLE));
        $this->assertFalse($db->schema()->hasColumn(DatabaseQueue::JOBS_TABLE, 'nonce'), 'and down() undoes it');
    }

    public function testPayloadsWrittenBefore213AreStillRead(): void
    {
        $legacy = serialize(new RecordJob('a'));   // 2.12 stored serialize() output as is

        $this->assertSame(RecordJob::class, Payload::className($legacy));
        $this->assertEquals(new RecordJob('a'), Payload::decode($legacy));
        $this->assertStringNotContainsString("\0", Payload::encode(new EncapsulatedJob('b', 1)));
    }
}
