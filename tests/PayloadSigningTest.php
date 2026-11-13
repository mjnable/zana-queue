<?php

declare(strict_types=1);

namespace Zana\Queue\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Zana\Configuration\Configuration;
use Zana\Queue\InMemoryQueue;
use Zana\Queue\Payload;
use Zana\Queue\PayloadKey;
use Zana\Queue\QueueException;
use Zana\Queue\QueueManager;

/**
 * Signed payloads (2.20, S28): a row the application did not write is
 * refused before unserialize() builds anything, so no class's __wakeup()
 * or __destruct() runs. The attack is the one of the security audit (M31).
 */
final class PayloadSigningTest extends TestCase
{
    private const string KEY = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk';

    private string $marker = '';

    #[After]
    public function removeMarker(): void
    {
        if ($this->marker !== '' && is_file($this->marker)) {
            unlink($this->marker);
        }
    }

    public function testJobsSurviveSigning(): void
    {
        $queue = new InMemoryQueue(key: new PayloadKey(self::KEY));
        $queue->push(new RecordJob('signed'));

        $popped = $queue->pop('default');
        $this->assertNotNull($popped);
        $this->assertTrue(PayloadKey::isSealed($popped->payload));
        $this->assertSame(RecordJob::class, $popped->className());
        $this->assertEquals(new RecordJob('signed'), $popped->job());
    }

    public function testARowTheApplicationDidNotWriteIsRefusedBeforeAnythingIsBuilt(): void
    {
        $key = new PayloadKey(self::KEY);
        $this->marker = sys_get_temp_dir() . '/zana-queue-gadget-' . bin2hex(random_bytes(4));
        $gadget = new Gadget();
        $gadget->file = $this->marker;
        $forged = base64_encode(serialize($gadget));
        $gadget->file = '';   // this test's own object must not write the file

        foreach (['unsigned' => $forged, 'signed with another key' => (new PayloadKey(str_repeat('x', 40)))->seal($forged), 'a made-up signature' => 'zs1.' . str_repeat('a', 64) . '.' . $forged] as $name => $row) {
            try {
                Payload::decode($row, $key);
                $this->fail("{$name}: the row must be refused.");
            } catch (QueueException $e) {
                $this->assertStringContainsString('not read', $e->getMessage(), $name);
            }
            gc_collect_cycles();
            $this->assertFileDoesNotExist($this->marker, "{$name}: no object was built, so no __destruct ran");
        }
    }

    public function testAChangedPayloadIsRefused(): void
    {
        $key = new PayloadKey(self::KEY);
        $sealed = Payload::encode(new RecordJob('amount 10'), $key);
        $changed = str_replace(base64_encode(serialize(new RecordJob('amount 10'))), base64_encode(serialize(new RecordJob('amount 99'))), $sealed);
        $this->assertNotSame($sealed, $changed);

        $this->expectException(QueueException::class);
        Payload::decode($changed, $key);
    }

    public function testJobsQueuedBefore220DrainWhenAllowed(): void
    {
        $old = Payload::encode(new RecordJob('queued under 2.19'));

        $this->assertEquals(new RecordJob('queued under 2.19'), Payload::decode($old, new PayloadKey(self::KEY, acceptUnsigned: true)));
        $this->expectException(QueueException::class);
        Payload::decode($old, new PayloadKey(self::KEY));
    }

    public function testWithoutAKeyNothingIsSignedAndASignedRowIsNotTrusted(): void
    {
        $none = new PayloadKey(null);
        $this->assertFalse(PayloadKey::isSealed(Payload::encode(new RecordJob('a'), $none)));
        $this->assertEquals(new RecordJob('a'), Payload::decode(Payload::encode(new RecordJob('a')), $none), 'as before 2.20');

        $this->expectException(QueueException::class);
        Payload::decode(Payload::encode(new RecordJob('a'), new PayloadKey(self::KEY)), $none);
    }

    public function testTheKeyComesFromConfiguration(): void
    {
        $this->assertTrue(QueueManager::payloadKey(new Configuration(['queue' => ['key' => self::KEY]]))->signs());
        $this->assertTrue(QueueManager::payloadKey(new Configuration(['security' => ['secret' => str_repeat('s', 40)]]))->signs(), 'derived from security.secret');
        $this->assertFalse(QueueManager::payloadKey(new Configuration([]))->signs());
        $this->assertTrue(QueueManager::payloadKey(new Configuration(['queue' => ['accept_unsigned' => true]]))->acceptsUnsigned());

        $fromSecret = QueueManager::payloadKey(new Configuration(['security' => ['secret' => str_repeat('s', 40)]]));
        $this->assertNotSame(Payload::encode(new RecordJob('a'), $fromSecret), 'zs1.' . hash_hmac('sha256', base64_encode(serialize(new RecordJob('a'))), str_repeat('s', 40)) . '.' . base64_encode(serialize(new RecordJob('a'))), 'the secret itself never signs');

        $this->expectException(QueueException::class);
        new PayloadKey('too short');
    }
}

final class Gadget
{
    public string $file = '';

    public function __destruct()
    {
        if ($this->file !== '') {
            file_put_contents($this->file, 'written by a forged payload');
        }
    }
}
