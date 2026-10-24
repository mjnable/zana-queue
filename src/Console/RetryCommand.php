<?php

declare(strict_types=1);

namespace Zana\Queue\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Queue\FailedJob;
use Zana\Queue\QueueException;
use Zana\Queue\QueueInterface;

#[AsCommand(name: 'queue:retry', description: 'Queue failed jobs again')]
final class RetryCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Failed job ids, or "all"');
    }

    public function handle(QueueInterface $queue, InputInterface $input, SymfonyStyle $io): int
    {
        /** @var list<string> $ids */
        $ids = $input->getArgument('id');
        if ($ids === ['all']) {
            $ids = array_map(static fn (FailedJob $job): string => $job->id, $queue->failed());
        }

        $status = self::SUCCESS;
        foreach ($ids as $id) {
            try {
                $io->writeln(sprintf('Failed job %s queued again as %s.', $id, $queue->retry($id)));
            } catch (QueueException $e) {
                $io->error($e->getMessage());
                $status = self::FAILURE;
            }
        }

        return $status;
    }
}
