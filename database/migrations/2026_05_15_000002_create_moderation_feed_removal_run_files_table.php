<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('moderation_feed_removal_run_files')) {
            $this->ensureRunForeignKey();

            return;
        }

        Schema::create('moderation_feed_removal_run_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('moderation_feed_removal_run_id');
            $table->unsignedBigInteger('file_id');
            $table->timestamps();

            $table->foreign(
                'moderation_feed_removal_run_id',
                'feed_removal_run_files_run_id_fk',
            )->references('id')->on('moderation_feed_removal_runs')->cascadeOnDelete();
            $table->unique(
                ['moderation_feed_removal_run_id', 'file_id'],
                'moderation_feed_removal_run_file_unique',
            );
            $table->index('file_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_feed_removal_run_files');
    }

    private function ensureRunForeignKey(): void
    {
        if (DB::getDriverName() !== 'mysql' || $this->runForeignKeyExists()) {
            return;
        }

        Schema::table('moderation_feed_removal_run_files', function (Blueprint $table) {
            $table->foreign(
                'moderation_feed_removal_run_id',
                'feed_removal_run_files_run_id_fk',
            )->references('id')->on('moderation_feed_removal_runs')->cascadeOnDelete();
        });
    }

    private function runForeignKeyExists(): bool
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'moderation_feed_removal_run_files')
            ->where('COLUMN_NAME', 'moderation_feed_removal_run_id')
            ->where('REFERENCED_TABLE_NAME', 'moderation_feed_removal_runs')
            ->exists();
    }
};
