<?php

declare(strict_types=1);

namespace Zana\Queue\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Zana\Console\ConsoleKernel;
use Zana\Console\ConsoleServiceProvider;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Core\Application;
use Zana\Database\DatabaseManager;
use Zana\Database\DatabaseServiceProvider;
use Zana\Queue\DatabaseQueue;
use Zana\Queue\Dispatcher;
use Zana\Queue\InMemoryQueue;
use Zana\Queue\PayloadKey;
use Zana\Queue\QueueException;
use Zana\Queue\QueueInterface;
use Zana\Queue\QueueManager;
use Zana\Queue\QueueServiceProvider;
use Zana\Queue\SyncQueue;
use Zana\Queue\Worker;

final class QueueServiceProviderTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        Journal::reset();
        $this->base = sys_get_temp_dir() . '/zana-queue-' . bin2hex(random_bytes(4));
        mkdir("{$this->base}/config", 0777, true);
        file_put_contents("{$this->base}/config/database.php", "<?php return ['connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']]];");
    }

    #[After]
    public function removeBase(): void
    {
        $remove = static function (string $path) use (&$remove): void {
            foreach (glob("{$path}/*") ?: [] as $item) {
                is_dir($item) ? $remove($item) : unlink($item);
            }
            rmdir($path);
        };
        $remove($this->base);
    }

    public function testTheDatabaseQueueEndToEnd(): void
    {
        $container = $this->container('database');
        $kernel = $container->get(ConsoleKernel::class);

        $this->assertStringContainsString('Created', $this->console($kernel, ['command' => 'queue:table']));
        $this->assertStringContainsString('already exists', $this->console($kernel, ['command' => 'queue:table']));
        $this->assertStringContainsString('Ran 1 migration', $this->console($kernel, ['command' => 'migrate']));

        $queue = $container->get(QueueInterface::class);
        $this->assertInstanceOf(DatabaseQueue::class, $queue);
        $this->assertSame($queue, $container->get(QueueManager::class)->driver());

        $dispatcher = $container->get(Dispatcher::class);
        $dispatcher->dispatch(new RecordJob('a'));
        $dispatcher->dispatch(new RecordJob('b'));
        $dispatcher->dispatch(new FailingJob());

        $this->assertStringContainsString('Processed 2 jobs from "default"', $this->console($kernel, ['command' => 'queue:work', '--max-jobs' => '2']));
        $this->assertSame(['handle:a', 'dispose:1', 'handle:b', 'dispose:2'], Journal::$entries);
        $this->assertStringContainsString('Processed 1 job from "default"', $this->console($kernel, ['command' => 'queue:work', '--stop-when-empty' => true]));

        $listing = $this->console($kernel, ['command' => 'queue:failed']);
        $this->assertStringContainsString(FailingJob::class, $listing);
        $this->assertStringContainsString('DomainException: patient not found', $listing);

        $logged = $container->get(MemoryLogger::class)->records;
        $this->assertSame('error', $logged[0][0], 'the worker logs to the registered logger');

        $this->assertStringContainsString('Failed job 1 queued again', $this->console($kernel, ['command' => 'queue:retry', 'id' => ['1']]));
        $this->assertSame(1, $queue->size('default'));
        $this->assertStringContainsString('No failed jobs', $this->console($kernel, ['command' => 'queue:failed']));
        $this->assertStringContainsString('There is no failed job with id "99"', $this->console($kernel, ['command' => 'queue:retry', 'id' => ['99']]));

        $this->console($kernel, ['command' => 'queue:work', '--stop-when-empty' => true]);
        $this->console($kernel, ['command' => 'queue:retry', 'id' => ['all']]);
        $this->assertSame(1, $queue->size('default'), 'retry all queues every failed job');
    }

    public function testTheDatabaseQueueSignsItsRowsAndRefusesForgedOnes(): void
    {
        file_put_contents("{$this->base}/config/security.php", "<?php return ['secret' => '" . str_repeat('s', 40) . "'];");
        $container = $this->container('database');
        $kernel = $container->get(ConsoleKernel::class);
        $this->console($kernel, ['command' => 'queue:table']);
        $this->console($kernel, ['command' => 'migrate']);
        $db = $container->get(DatabaseManager::class)->connection();

        $container->get(Dispatcher::class)->dispatch(new RecordJob('signed'));
        $container->get(Dispatcher::class)->dispatch(new RecordJob('to be replaced'));
        $rows = $db->table(DatabaseQueue::JOBS_TABLE)->orderBy('id')->get();
        $this->assertTrue(PayloadKey::isSealed((string) $rows[0]['payload']), 'stored signed');

        // Someone with write access to the table replaces a row's payload.
        $marker = "{$this->base}/gadget-ran";
        $gadget = new Gadget();
        $gadget->file = $marker;
        $db->table(DatabaseQueue::JOBS_TABLE)->where('id', $rows[1]['id'])->update(['payload' => base64_encode(serialize($gadget))]);
        $gadget->file = '';

        $this->console($kernel, ['command' => 'queue:work', '--stop-when-empty' => true]);
        gc_collect_cycles();

        $this->assertSame(['handle:signed', 'dispose:1'], Journal::$entries, 'the signed job ran');
        $this->assertFileDoesNotExist($marker, 'the forged row built nothing');
        $this->assertStringContainsString('not signed', $this->console($kernel, ['command' => 'queue:failed']), 'and is listed as failed, with the reason');
        $this->assertStringNotContainsString('queue.payload_signing', $this->console($kernel, ['command' => 'check']));
    }

    public function testCheckWarnsWhenTheQueueIsNotSigned(): void
    {
        $kernel = $this->container('database')->get(ConsoleKernel::class);
        $this->assertStringContainsString('Queued jobs are not signed', $this->console($kernel, ['command' => 'check']));

        file_put_contents("{$this->base}/config/security.php", "<?php return ['secret' => '" . str_repeat('s', 40) . "'];");
        file_put_contents("{$this->base}/config/queue.php", "<?php return ['default' => 'database', 'accept_unsigned' => true];");
        $kernel = (function (): ContainerInterface {
            return Application::configure($this->base)->withProviders(ConsoleServiceProvider::class, DatabaseServiceProvider::class, QueueServiceProvider::class)->build()->container();
        })()->get(ConsoleKernel::class);
        $this->assertStringContainsString('queue.accept_unsigned is on', $this->console($kernel, ['command' => 'check']));
    }

    public function testInvalidWorkOptions(): void
    {
        $kernel = $this->container('memory')->get(ConsoleKernel::class);

        $this->assertStringContainsString('--max-jobs must be a positive integer', $this->console($kernel, ['command' => 'queue:work', '--max-jobs' => '0']));
        $this->assertStringContainsString('--sleep must be zero or a positive integer', $this->console($kernel, ['command' => 'queue:work', '--sleep' => 'x']));
    }

    public function testDrivers(): void
    {
        $this->assertInstanceOf(SyncQueue::class, $this->container(null)->get(QueueInterface::class), 'sync is the default');

        $container = $this->container('memory');
        $this->assertInstanceOf(InMemoryQueue::class, $container->get(QueueInterface::class));
        $this->assertSame($container->get(QueueInterface::class), $container->get(QueueManager::class)->driver('memory'));
        $this->assertInstanceOf(Worker::class, $container->get(Worker::class));

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('Unknown queue driver: "redis"');
        $container->get(QueueManager::class)->driver('redis');
    }

    public function testTheSyncDriverRunsJobsInAScope(): void
    {
        $this->container(null)->get(Dispatcher::class)->dispatch(new RecordJob('now'));

        $this->assertSame(['handle:now', 'dispose:sync-1'], Journal::$entries);
    }

    private function container(?string $driver): ContainerInterface
    {
        if ($driver !== null) {
            file_put_contents("{$this->base}/config/queue.php", "<?php return ['default' => '{$driver}'];");
        }

        return Application::configure($this->base)
            ->withProviders(ConsoleServiceProvider::class, DatabaseServiceProvider::class, QueueServiceProvider::class)
            ->withServices(static function (ServiceCollectionInterface $s): void {
                $s->scoped(JobContext::class)
                    ->singleton(MemoryLogger::class)
                    ->singleton(\Psr\Log\LoggerInterface::class, static fn ($r) => $r->get(MemoryLogger::class));
            })
            ->build()
            ->container();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(ConsoleKernel $kernel, array $input): string
    {
        $output = new BufferedOutput();
        $kernel->run(new ArrayInput($input), $output);

        return $output->fetch();
    }
}
