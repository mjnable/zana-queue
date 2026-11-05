<?php

declare(strict_types=1);

namespace Zana\Queue\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zana\Database\ConnectionFactory;
use Zana\Queue\DatabaseQueue;
use Zana\Queue\Payload;
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

    public function testPayloadsWrittenBefore213AreStillRead(): void
    {
        $legacy = serialize(new RecordJob('a'));   // 2.12 stored serialize() output as is

        $this->assertSame(RecordJob::class, Payload::className($legacy));
        $this->assertEquals(new RecordJob('a'), Payload::decode($legacy));
        $this->assertStringNotContainsString("\0", Payload::encode(new EncapsulatedJob('b', 1)));
    }
}
