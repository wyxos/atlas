<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EnsureBrowseIndexes extends Command
{
    protected $signature = 'atlas:ensure-browse-indexes
        {--dry-run : Preview missing browse indexes without executing DDL}
        {--only= : Restrict execution to "files" or "reactions"}';

    protected $description = 'Ensure the large-table indexes used by local browse exist';

    public function handle(): int
    {
        $groups = $this->groupsToProcess((string) $this->option('only'));
        if ($groups === null) {
            $this->error('The --only option must be "files" or "reactions".');

            return self::FAILURE;
        }

        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->warn("Skipping browse index DDL for {$driver}; only mysql/mariadb are supported.");

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $definitions = $this->indexDefinitions();
        $processed = 0;
        $created = 0;

        foreach ($groups as $group) {
            foreach ($definitions[$group] as $definition) {
                $processed++;

                if ($this->indexExists($definition['table'], $definition['name'])) {
                    $this->line("Exists {$definition['name']}");

                    continue;
                }

                if ($dryRun) {
                    $this->line(sprintf(
                        'Would create %s on %s (%s)',
                        $definition['name'],
                        $definition['table'],
                        implode(', ', $definition['columns']),
                    ));

                    continue;
                }

                if ($this->createIndex($definition)) {
                    $created++;
                    $this->info("Created {$definition['name']}");

                    continue;
                }

                $this->line("Exists {$definition['name']}");
            }
        }

        if ($dryRun) {
            $this->info("Dry run complete. Checked {$processed} browse indexes.");

            return self::SUCCESS;
        }

        $this->info("Browse index check complete. Created {$created} index(es).");

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>|null
     */
    private function groupsToProcess(string $only): ?array
    {
        $only = strtolower(trim($only));
        if ($only === '') {
            return ['files', 'reactions'];
        }

        return in_array($only, ['files', 'reactions'], true) ? [$only] : null;
    }

    /**
     * @return array<string, array<int, array{name: string, table: string, columns: array<int, string>}>>
     */
    private function indexDefinitions(): array
    {
        return [
            'files' => [
                [
                    'name' => 'files_downloaded_at_updated_at_id_idx',
                    'table' => 'files',
                    'columns' => ['downloaded_at', 'updated_at', 'id'],
                ],
                [
                    'name' => 'files_created_at_id_idx',
                    'table' => 'files',
                    'columns' => ['created_at', 'id'],
                ],
                [
                    'name' => 'files_updated_at_id_idx',
                    'table' => 'files',
                    'columns' => ['updated_at', 'id'],
                ],
                [
                    'name' => 'files_blacklisted_at_updated_at_id_idx',
                    'table' => 'files',
                    'columns' => ['blacklisted_at', 'updated_at', 'id'],
                ],
                [
                    'name' => 'files_source_updated_at_id_idx',
                    'table' => 'files',
                    'columns' => ['source', 'updated_at', 'id'],
                ],
            ],
            'reactions' => [
                [
                    'name' => 'reactions_file_user_idx',
                    'table' => 'reactions',
                    'columns' => ['file_id', 'user_id'],
                ],
                [
                    'name' => 'reactions_file_user_type_idx',
                    'table' => 'reactions',
                    'columns' => ['file_id', 'user_id', 'type'],
                ],
            ],
        ];
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $rows = DB::select(
            sprintf('SHOW INDEX FROM %s WHERE Key_name = ?', $this->quoteIdentifier($table)),
            [$indexName],
        );

        return $rows !== [];
    }

    /**
     * @param  array{name: string, table: string, columns: array<int, string>}  $definition
     */
    private function createIndex(array $definition): bool
    {
        $table = $this->quoteIdentifier($definition['table']);
        $index = $this->quoteIdentifier($definition['name']);
        $columns = implode(', ', array_map(fn (string $column): string => $this->quoteIdentifier($column), $definition['columns']));

        try {
            DB::statement("ALTER TABLE {$table} ADD INDEX {$index} ({$columns}), ALGORITHM=INPLACE, LOCK=NONE");

            return true;
        } catch (\Throwable $e) {
            if ($this->isDuplicateIndexError($e)) {
                return false;
            }

            if (! $this->isOnlineDdlUnsupportedError($e)) {
                throw $e;
            }

            try {
                DB::statement("ALTER TABLE {$table} ADD INDEX {$index} ({$columns}), ALGORITHM=COPY");

                return true;
            } catch (\Throwable $fallbackException) {
                if ($this->isDuplicateIndexError($fallbackException)) {
                    return false;
                }

                throw $fallbackException;
            }
        }
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
