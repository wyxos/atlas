<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EnsureFilesImportedAtColumn extends Command
{
    protected $signature = 'atlas:ensure-files-imported-at-column {--dry-run : Preview DDL without executing it}';

    protected $description = 'Ensure the files.imported_at column and supporting index exist for library scans';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $columnExists = Schema::hasColumn('files', 'imported_at');
        $indexExists = $this->indexExists('files_imported_at_updated_at_id_idx');

        if ($columnExists && $indexExists) {
            $this->info('files.imported_at and files_imported_at_updated_at_id_idx already exist.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            if (! $columnExists) {
                $this->line('Would add nullable files.imported_at timestamp column.');
            }

            if (! $indexExists) {
                $this->line('Would add files_imported_at_updated_at_id_idx on imported_at, updated_at, id.');
            }

            return self::SUCCESS;
        }

        if (! $columnExists) {
            $this->addImportedAtColumn();
            $this->info('Added files.imported_at.');
        }

        if (! $indexExists) {
            $this->addImportedAtIndex();
            $this->info('Added files_imported_at_updated_at_id_idx.');
        }

        return self::SUCCESS;
    }

    private function addImportedAtColumn(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->runOnlineDdl(
                'ALTER TABLE `files` ADD COLUMN `imported_at` TIMESTAMP NULL AFTER `downloaded_at`, ALGORITHM=INPLACE, LOCK=NONE',
                'ALTER TABLE `files` ADD COLUMN `imported_at` TIMESTAMP NULL AFTER `downloaded_at`, ALGORITHM=COPY',
            );

            return;
        }

        Schema::table('files', function ($table) {
            $table->timestamp('imported_at')->nullable()->after('downloaded_at');
        });
    }

    private function addImportedAtIndex(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->runOnlineDdl(
                'ALTER TABLE `files` ADD INDEX `files_imported_at_updated_at_id_idx` (`imported_at`, `updated_at`, `id`), ALGORITHM=INPLACE, LOCK=NONE',
                'ALTER TABLE `files` ADD INDEX `files_imported_at_updated_at_id_idx` (`imported_at`, `updated_at`, `id`), ALGORITHM=COPY',
            );

            return;
        }

        Schema::table('files', function ($table) {
            $table->index(['imported_at', 'updated_at', 'id'], 'files_imported_at_updated_at_id_idx');
        });
    }

    private function indexExists(string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('files')"))
                ->contains(fn (object $row): bool => ($row->name ?? null) === $indexName);
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return collect(Schema::getIndexes('files'))
                ->contains(fn (array $index): bool => ($index['name'] ?? null) === $indexName);
        }

        return DB::select('SHOW INDEX FROM `files` WHERE Key_name = ?', [$indexName]) !== [];
    }

    private function runOnlineDdl(string $onlineSql, string $fallbackSql): void
    {
        try {
            DB::statement($onlineSql);

            return;
        } catch (\Throwable $e) {
            if (! $this->isOnlineDdlUnsupportedError($e)) {
                throw $e;
            }
        }

        DB::statement($fallbackSql);
    }

    private function isOnlineDdlUnsupportedError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'algorithm=inplace is not supported')
            || str_contains($message, 'feature not supported: 1846')
            || str_contains($message, 'try algorithm=copy');
    }
}
