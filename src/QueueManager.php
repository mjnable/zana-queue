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
            'sync' => new SyncQueue($this->root, $this->logger(), self::payloadKey($this->config)),
            'memory' => new InMemoryQueue(key: self::payloadKey($this->config)),
            'database' => new DatabaseQueue(
                $this->root->get(DatabaseManager::class)->connection($this->config->has('queue.connection') ? $this->config->string('queue.connection') : null),
                $this->config->int('queue.retry_after', 90),
                key: self::payloadKey($this->config),
            ),
            default => throw new QueueException("Unknown queue driver: \"{$name}\". Use sync, memory or database."),
        };
    }

    /**
     * The key that signs payloads (2.20): queue.key, else one derived from
     * security.secret, else none; queue.accept_unsigned lets payloads from
     * before 2.20 through while the queue drains.
     */
    public static function payloadKey(ConfigurationInterface $config): PayloadKey
    {
        $accept = $config->bool('queue.accept_unsigned', false);
        if ($config->has('queue.key')) {
            return new PayloadKey($config->string('queue.key'), $accept);
        }

        return PayloadKey::fromSecret($config->has('security.secret') ? $config->string('security.secret') : null, 'zana.queue.payload', $accept);
    }

    private function logger(): ?LoggerInterface
    {
        return $this->root->has(LoggerInterface::class) ? $this->root->get(LoggerInterface::class) : null;
    }
}
