<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EnsureLibraryScanIndexes extends Command
{
    protected $signature = 'atlas:ensure-library-scan-indexes
        {--dry-run : Preview missing library scan indexes without executing DDL}
        {--allow-copy : Allow blocking ALGORITHM=COPY fallback if online DDL is unsupported}';

    protected $description = 'Ensure large-table indexes needed by library scans exist';

    public function handle(): int
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->warn("Skipping library scan index DDL for {$driver}; only mysql/mariadb are supported.");

            return self::SUCCESS;
        }

        if ($this->indexExists('files', 'files_hash_index')) {
            $this->info('files_hash_index already exists.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            $this->line('Would create files_hash_index on files (hash).');

            return self::SUCCESS;
        }

        try {
            DB::statement('ALTER TABLE `files` ADD INDEX `files_hash_index` (`hash`), ALGORITHM=INPLACE, LOCK=NONE');
            $this->info('Created files_hash_index.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            if ($this->isDuplicateIndexError($e)) {
                $this->info('files_hash_index already exists.');

                return self::SUCCESS;
            }

            if (! $this->isOnlineDdlUnsupportedError($e) || ! (bool) $this->option('allow-copy')) {
                $this->error($e->getMessage());
                $this->warn('Online DDL failed. Re-run with --allow-copy only during a planned maintenance window if a blocking table copy is acceptable.');

                return self::FAILURE;
            }
        }

        DB::statement('ALTER TABLE `files` ADD INDEX `files_hash_index` (`hash`), ALGORITHM=COPY');
        $this->info('Created files_hash_index with ALGORITHM=COPY.');

        return self::SUCCESS;
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(fn (object $row): bool => ($row->name ?? null) === $indexName);
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return collect(Schema::getIndexes($table))
                ->contains(fn (array $index): bool => ($index['name'] ?? null) === $indexName);
        }

        return DB::select(
            sprintf('SHOW INDEX FROM %s WHERE Key_name = ?', $this->quoteIdentifier($table)),
            [$indexName],
        ) !== [];
    }

    private function quoteIdentifier(string $value): string
    {
        return '`'.str_replace('`', '``', $value).'`';
    }

    private function isDuplicateIndexError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate key name')
            || str_contains($message, 'already exists')
            || str_contains($message, 'duplicate index');
    }

    private function isOnlineDdlUnsupportedError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'algorithm=inplace is not supported')
            || str_contains($message, 'feature not supported: 1846')
            || str_contains($message, 'try algorithm=copy');
    }
}
