<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string INDEX_NAME = 'files_mime_type_id_index';

    public function up(): void
    {
        try {
            $driver = DB::connection()->getDriverName();

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                // Build the index with online DDL to avoid long blocking locks on large files tables.
                DB::statement(sprintf(
                    'ALTER TABLE files ADD INDEX %s (mime_type, id), ALGORITHM=INPLACE, LOCK=NONE',
                    self::INDEX_NAME
                ));

                return;
            }

            Schema::table('files', function (Blueprint $table): void {
                $table->index(['mime_type', 'id'], self::INDEX_NAME);
            });
        } catch (\Throwable $e) {
            if ($this->isDuplicateIndexError($e)) {
                return;
            }

            throw $e;
        }
    }

    public function down(): void
    {
        try {
            Schema::table('files', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX_NAME);
            });
        } catch (\Throwable) {
            // Ignore when index does not exist.
        }
    }

    private function isDuplicateIndexError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate key name')
            || str_contains($message, 'already exists')
            || str_contains($message, 'duplicate index');
    }
};
