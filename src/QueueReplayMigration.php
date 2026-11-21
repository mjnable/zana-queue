<?php

declare(strict_types=1);

namespace Zana\Queue;

use Zana\Database\Migration\Migration;
use Zana\Database\Schema\Blueprint;
use Zana\Database\Schema\Schema;

/**
 * Refuses copies of signed jobs (2.34): a unique nonce on zana_jobs, so a
 * copy cannot be added while its job waits, and zana_jobs_done, the nonces
 * of the jobs that ran, kept for queue.replay_window. queue:table writes a
 * migration file that returns this class, after the tables' own.
 */
final class QueueReplayMigration extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->table(DatabaseQueue::JOBS_TABLE, static function (Blueprint $t): void {
            $t->string('nonce', 32)->nullable()->unique();
        });
        $schema->create(DatabaseQueue::DONE_TABLE, static function (Blueprint $t): void {
            $t->string('nonce', 32);
            $t->bigInteger('done_at');
            $t->unique('nonce');
            $t->index('done_at');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists(DatabaseQueue::DONE_TABLE);
        $schema->table(DatabaseQueue::JOBS_TABLE, static function (Blueprint $t): void {
            $t->dropIndex(DatabaseQueue::JOBS_TABLE . '_nonce_unique');
            $t->dropColumn('nonce');
        });
    }
}
