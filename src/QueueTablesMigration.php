<?php

declare(strict_types=1);

namespace Zana\Queue;

use Zana\Database\Migration\Migration;
use Zana\Database\Schema\Blueprint;
use Zana\Database\Schema\Schema;

/**
 * Creates zana_jobs and zana_failed_jobs. queue:table writes a migration file
 * that returns this class.
 */
final class QueueTablesMigration extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create(DatabaseQueue::JOBS_TABLE, static function (Blueprint $t): void {
            $t->id();
            $t->string('queue', 100);
            $t->text('payload');
            $t->integer('attempts')->default(0);
            $t->bigInteger('reserved_at')->nullable();
            $t->bigInteger('available_at');
            $t->bigInteger('created_at');
            $t->index(['queue', 'available_at']);
        });

        $schema->create(DatabaseQueue::FAILED_TABLE, static function (Blueprint $t): void {
            $t->id();
            $t->string('queue', 100);
            $t->text('payload');
            $t->text('error');
            $t->bigInteger('failed_at');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists(DatabaseQueue::FAILED_TABLE);
        $schema->dropIfExists(DatabaseQueue::JOBS_TABLE);
    }
}
