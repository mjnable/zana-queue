<?php

declare(strict_types=1);

namespace Zana\Queue\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Core\ApplicationInterface;

#[AsCommand(name: 'queue:table', description: 'Create the migration for the database queue tables')]
final class TableCommand extends Command
{
    public function handle(ApplicationInterface $app, SymfonyStyle $io): int
    {
        $directory = $app->path('database', 'migrations');
        if ((glob("{$directory}/*_create_queue_tables.php") ?: []) !== []) {
            $io->warning('The queue tables migration already exists.');

            return self::SUCCESS;
        }

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            $io->error("Cannot create {$directory}.");

            return self::FAILURE;
        }

        $file = $directory . '/' . date('Y_m_d_His') . '_create_queue_tables.php';
        file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\nreturn new \\Zana\\Queue\\QueueTablesMigration();\n");
        $io->success("Created {$file}. Run migrate to create the tables.");

        return self::SUCCESS;
    }
}
