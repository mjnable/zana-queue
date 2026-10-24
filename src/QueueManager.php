<?php

declare(strict_types=1);

namespace Zana\Queue;

use Psr\Log\LoggerInterface;
use Zana\Configuration\ConfigurationInterface;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Database\DatabaseManager;

/**
 * Creates the queue drivers from configuration:
 *
 *     'queue' => [
 *         'default' => 'database',     // sync (the default), memory or database
 *         'connection' => 'main',      // database connection; the default one when omitted
 *         'retry_after' => 90,         // seconds before an abandoned reservation is taken again
 *     ]
 */
final class QueueManager
{
    /** @var array<string, QueueInterface> */
    private array $drivers = [];

    public function __construct(
        private readonly ConfigurationInterface $config,
        private readonly ContainerInterface $root,
    ) {
    }

    /**
     * @param string|null $name "sync", "memory" or "database"; defaults to queue.default
     */
    public function driver(?string $name = null): QueueInterface
    {
        $name ??= $this->config->string('queue.default', 'sync');

        return $this->drivers[$name] ??= match ($name) {
            'sync' => new SyncQueue($this->root, $this->logger()),
            'memory' => new InMemoryQueue(),
            'database' => new DatabaseQueue(
                $this->root->get(DatabaseManager::class)->connection($this->config->has('queue.connection') ? $this->config->string('queue.connection') : null),
                $this->config->int('queue.retry_after', 90),
            ),
            default => throw new QueueException("Unknown queue driver: \"{$name}\". Use sync, memory or database."),
        };
    }

    private function logger(): ?LoggerInterface
    {
        return $this->root->has(LoggerInterface::class) ? $this->root->get(LoggerInterface::class) : null;
    }
}
