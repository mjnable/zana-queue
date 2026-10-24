<?php

declare(strict_types=1);

namespace Zana\Queue\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Queue\Worker;

#[AsCommand(name: 'queue:work', description: 'Process queued jobs')]
final class WorkCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('queue', InputArgument::OPTIONAL, 'The queue to process', 'default')
            ->addOption('max-jobs', null, InputOption::VALUE_REQUIRED, 'Stop after this many jobs')
            ->addOption('stop-when-empty', null, InputOption::VALUE_NONE, 'Stop when the queue is empty')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Seconds to wait when the queue is empty', '1');
    }

    public function handle(Worker $worker, InputInterface $input, SymfonyStyle $io): int
    {
        $maxJobs = $input->getOption('max-jobs');
        if ($maxJobs !== null) {
            $maxJobs = filter_var($maxJobs, FILTER_VALIDATE_INT);
            if (!is_int($maxJobs) || $maxJobs < 1) {
                $io->error('--max-jobs must be a positive integer.');

                return self::INVALID;
            }
        }

        $sleep = filter_var($input->getOption('sleep'), FILTER_VALIDATE_INT);
        if (!is_int($sleep) || $sleep < 0) {
            $io->error('--sleep must be zero or a positive integer.');

            return self::INVALID;
        }

        $queue = (string) $input->getArgument('queue');
        $processed = $worker->work($queue, $maxJobs, (bool) $input->getOption('stop-when-empty'), $sleep);
        $io->success(sprintf('Processed %d job%s from "%s".', $processed, $processed === 1 ? '' : 's', $queue));

        return self::SUCCESS;
    }
}
