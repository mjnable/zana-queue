<?php

declare(strict_types=1);

namespace Zana\Queue\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Core\ApplicationInterface;

/**
 * Writes the migrations of the database queue: its tables, and since 2.34 the
 * refusal of copied jobs (QueueReplayMigration). Run again after an upgrade,
 * it writes only what is missing.
 */
#[AsCommand(name: 'queue:table', description: 'Create the migrations for the database queue tables')]
final class TableCommand extends Command
{
    private const array MIGRATIONS = [
        'create_queue_tables' => ['Zana\Queue\QueueTablesMigration', 'the queue tables'],
        'refuse_copied_queue_jobs' => ['Zana\Queue\QueueReplayMigration', 'the refusal of copied jobs (2.34)'],
    ];

    public function handle(ApplicationInterface $app, SymfonyStyle $io): int
    {
        $directory = $app->path('database', 'migrations');
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            $io->error("Cannot create {$directory}.");

            return self::FAILURE;
        }

        $written = 0;
        $time = time();
        foreach (self::MIGRATIONS as $name => [$class, $what]) {
            if ((glob("{$directory}/*_{$name}.php") ?: []) !== []) {
                continue;
            }
            // One second apart, so the tables are created before they are changed.
            $file = $directory . '/' . date('Y_m_d_His', $time + $written) . "_{$name}.php";
            file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\nreturn new \\{$class}();\n");
            $io->success("Created {$file}, for {$what}.");
            $written++;
        }
        if ($written === 0) {
            $io->warning('The queue migrations already exist.');

            return self::SUCCESS;
        }
        $io->text('Run migrate to apply them.');

        return self::SUCCESS;
    }
}
