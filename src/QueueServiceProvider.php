<?php

declare(strict_types=1);

namespace Zana\Queue;

use Psr\Log\LoggerInterface;
use Zana\Configuration\ConfigurationInterface;
use Zana\Console\ConsoleKernel;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Core\ServiceProvider;
use Zana\Queue\Console\FailedCommand;
use Zana\Queue\Console\RetryCommand;
use Zana\Queue\Console\TableCommand;
use Zana\Queue\Console\WorkCommand;

/**
 * Registers the queue manager, the default QueueInterface, the dispatcher,
 * the worker and the queue commands. The database driver needs
 * DatabaseServiceProvider; a PSR-3 logger is used when one is registered.
 */
final class QueueServiceProvider extends ServiceProvider
{
    public function register(ServiceCollectionInterface $services): void
    {
        $services
            ->singleton(QueueManager::class, static function (ServiceResolverInterface $root): QueueManager {
                assert($root instanceof ContainerInterface);

                return new QueueManager($root->get(ConfigurationInterface::class), $root);
            })->uses(ConfigurationInterface::class)
            ->singleton(QueueInterface::class, static fn (ServiceResolverInterface $r): QueueInterface => $r->get(QueueManager::class)->driver())->uses(QueueManager::class)
            ->singleton(Dispatcher::class)
            ->singleton(Worker::class, static function (ServiceResolverInterface $root): Worker {
                assert($root instanceof ContainerInterface);

                return new Worker(
                    $root->get(QueueInterface::class),
                    $root,
                    $root->has(LoggerInterface::class) ? $root->get(LoggerInterface::class) : null,
                );
            })->uses(QueueInterface::class, LoggerInterface::class);

        foreach ([WorkCommand::class, FailedCommand::class, RetryCommand::class, TableCommand::class] as $command) {
            $services->singleton($command)->tag(ConsoleKernel::TAG, $command);
        }
    }

    public function origin(): string
    {
        return 'package:mj-zana/queue';
    }
}
