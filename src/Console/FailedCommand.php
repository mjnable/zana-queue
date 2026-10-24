<?php

declare(strict_types=1);

namespace Zana\Queue\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Queue\FailedJob;
use Zana\Queue\QueueInterface;

#[AsCommand(name: 'queue:failed', description: 'List the failed jobs')]
final class FailedCommand extends Command
{
    public function handle(QueueInterface $queue, SymfonyStyle $io): int
    {
        $failed = $queue->failed();
        if ($failed === []) {
            $io->success('No failed jobs.');

            return self::SUCCESS;
        }

        $io->table(['Id', 'Queue', 'Job', 'Failed at', 'Error'], array_map(
            static fn (FailedJob $job): array => [$job->id, $job->queue, $job->className() ?? 'unknown', $job->failedAt->format('Y-m-d H:i:s'), $job->error],
            $failed,
        ));

        return self::SUCCESS;
    }
}
