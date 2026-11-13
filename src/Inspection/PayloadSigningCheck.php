<?php

declare(strict_types=1);

namespace Zana\Queue\Inspection;

use Zana\Configuration\ConfigurationInterface;
use Zana\Core\ApplicationInterface;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Core\Inspection\Finding;
use Zana\Core\Inspection\Severity;
use Zana\Queue\QueueManager;

/**
 * queue.payload_signing (2.20, S28): a queue that stores its jobs signs them.
 * A warning, as an unsigned queue still runs: without a key, a row written
 * to the queue's table by anything but the application is read as a job.
 */
final readonly class PayloadSigningCheck implements ApplicationCheck
{
    public function findings(ApplicationInterface $application): array
    {
        $config = $application->container()->get(ConfigurationInterface::class);
        assert($config instanceof ConfigurationInterface);
        if ($config->string('queue.default', 'sync') !== 'database') {
            return [];   // sync and memory queues never store a payload
        }

        $key = QueueManager::payloadKey($config);
        $findings = [];
        if (!$key->signs()) {
            $findings[] = new Finding('queue.payload_signing', Severity::Warning, 'Queued jobs are not signed, so a row added to the queue table by anything but the application is read as a job.', hint: 'Set security.secret, or queue.key.');
        }
        if ($key->acceptsUnsigned()) {
            $findings[] = new Finding('queue.payload_signing', Severity::Warning, 'queue.accept_unsigned is on, so unsigned rows are read as jobs.', hint: 'Turn it off once the jobs queued before 2.20 have run.');
        }

        return $findings;
    }
}
