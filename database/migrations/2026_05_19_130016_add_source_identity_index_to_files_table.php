<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string INDEX_NAME = 'files_source_source_id_idx';

    public function up(): void
    {
        if ($this->indexExists()) {
            return;
        }

        if ($this->usesOnlineDdl()) {
            try {
                DB::statement(sprintf(
                    'ALTER TABLE `files` ADD INDEX `%s` (`source`, `source_id`), ALGORITHM=INPLACE, LOCK=NONE',
                    self::INDEX_NAME,
                ));
            } catch (\Throwable $e) {
                if ($this->isDuplicateIndexError($e)) {
                    return;
                }

                if ($this->requiresTableCopy($e)) {
                    logger()->warning('Skipped files source/source_id index because the database requires table-copy DDL.', [
                        'index' => self::INDEX_NAME,
                    ]);

                    return;
                }

                throw $e;
            }

            return;
        }

        Schema::table('files', static function (Blueprint $table): void {
            $table->index(['source', 'source_id'], self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        Schema::whenTableHasIndex('files', self::INDEX_NAME, static function (Blueprint $table): void {
            $table->dropIndex(self::INDEX_NAME);
        });
    }

    private function indexExists(): bool
    {
        if ($this->usesOnlineDdl()) {
            return DB::select('SHOW INDEX FROM `files` WHERE Key_name = ?', [self::INDEX_NAME]) !== [];
        }

        return Schema::hasIndex('files', self::INDEX_NAME);
    }

    private function usesOnlineDdl(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function isDuplicateIndexError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate key name')
            || str_contains($message, 'already exists')
            || str_contains($message, 'duplicate index');
    }

    private function requiresTableCopy(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'try algorithm=copy')
            || str_contains($message, 'try algorithm copy')
            || str_contains($message, 'algorithm=copy')
            || str_contains($message, 'sqlstate[0a000]: feature not supported: 1846');
    }
};
