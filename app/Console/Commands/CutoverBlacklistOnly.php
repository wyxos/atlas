<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Services\DownloadedFileClearService;
use App\Services\Local\LocalBrowseIndexSyncService;
use App\Services\MetricsService;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CutoverBlacklistOnly extends Command
{
    protected $signature = 'atlas:cutover-blacklist-only
        {--chunk=1000 : Number of file rows to process per chunk}
        {--dry-run : Report what would change without mutating data}
        {--skip-ddl : Convert data but skip the files.auto_disliked to files.auto_blacklisted rename}
        {--allow-copy-ddl : Permit MariaDB/MySQL COPY algorithm fallback when online rename is unsupported}';

    protected $description = 'Convert legacy dislike/auto-dislike state to the blacklist-only outcome model';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $skipDdl = (bool) $this->option('skip-ddl');
        $allowCopyDdl = (bool) $this->option('allow-copy-ddl');
        $autoColumn = $this->resolveAutoColumn();

        if ($autoColumn === null) {
            $this->error('Neither files.auto_blacklisted nor files.auto_disliked exists.');

            return self::FAILURE;
        }

        $this->line('Blacklist-only cutover scan:');
        $this->line('- auto provenance column: files.'.$autoColumn);
        $this->line('- auto-origin files: '.$this->autoOriginFileQuery($autoColumn)->count());
        $this->line('- files with dislike reactions: '.DB::table('reactions')->where('type', 'dislike')->distinct()->count('file_id'));
        $this->line('- moderation rules with dislike action: '.$this->countActionTypeRows('moderation_rules'));
        $this->line('- containers with dislike action: '.$this->countActionTypeRows('containers'));
        $this->line('- file moderation actions with dislike action: '.$this->countActionTypeRows('file_moderation_actions'));

        if ($dryRun) {
            $this->warn('Dry run only. No rows were changed.');

            return self::SUCCESS;
        }

        $this->normalizeActionTypes();
        $this->convertAutoOriginFiles($autoColumn, $chunk);
        $this->convertDislikeReactionFiles($autoColumn, $chunk);

        if ($autoColumn === 'auto_disliked' && ! $skipDdl) {
            $this->renameAutoDislikedColumn($allowCopyDdl);
            $autoColumn = 'auto_blacklisted';
        } elseif ($autoColumn === 'auto_disliked') {
            $this->warn('Skipped DDL rename. Run the command again without --skip-ddl before serving the new app code.');
        }

        app(MetricsService::class)->syncAll();

        if ($autoColumn === 'auto_blacklisted') {
            $this->syncLocalIndexes($chunk);
        }

        $this->info('Blacklist-only cutover complete.');

        return self::SUCCESS;
    }

    private function resolveAutoColumn(): ?string
    {
        if (Schema::hasColumn('files', 'auto_blacklisted')) {
            return 'auto_blacklisted';
        }

        if (Schema::hasColumn('files', 'auto_disliked')) {
            return 'auto_disliked';
        }

        return null;
    }

    private function normalizeActionTypes(): void
    {
        $now = now();

        foreach (['moderation_rules', 'containers', 'file_moderation_actions'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)
                ->where('action_type', 'dislike')
                ->update([
                    'action_type' => 'blacklist',
                    'updated_at' => $now,
                ]);
        }
    }

    private function convertAutoOriginFiles(string $autoColumn, int $chunk): void
    {
        $this->info('Converting auto-origin rows to blacklists...');

        $this->chunkFileIds($this->autoOriginFileQuery($autoColumn), $chunk, function (array $fileIds) use ($autoColumn): void {
            $now = now();

            DB::transaction(function () use ($autoColumn, $fileIds, $now): void {
                DB::table('files')
                    ->whereIn('id', $fileIds)
                    ->whereNull('blacklisted_at')
                    ->update([
                        'blacklisted_at' => $now,
                        'updated_at' => $now,
                    ]);

                DB::table('files')
                    ->whereIn('id', $fileIds)
                    ->update([
                        $autoColumn => true,
                        'updated_at' => $now,
                    ]);

                DB::table('reactions')
                    ->whereIn('file_id', $fileIds)
                    ->delete();
            });

            $this->clearDownloadedAssets($fileIds, syncIndex: $autoColumn === 'auto_blacklisted');
        });
    }

    private function convertDislikeReactionFiles(string $autoColumn, int $chunk): void
    {
        $this->info('Converting remaining dislike reactions to manual blacklists...');

        $this->chunkFileIds($this->dislikeReactionFileQuery(), $chunk, function (array $fileIds) use ($autoColumn): void {
            $now = now();

            DB::transaction(function () use ($autoColumn, $fileIds, $now): void {
                DB::table('files')
                    ->whereIn('id', $fileIds)
                    ->whereNull('blacklisted_at')
                    ->update([
                        'blacklisted_at' => $now,
                        'updated_at' => $now,
                    ]);

                DB::table('files')
                    ->whereIn('id', $fileIds)
                    ->where(function ($query) use ($autoColumn) {
                        $query->where($autoColumn, false)
                            ->orWhereNull($autoColumn);
                    })
                    ->update([
                        $autoColumn => false,
                        'updated_at' => $now,
                    ]);

                DB::table('reactions')
                    ->whereIn('file_id', $fileIds)
                    ->delete();
            });

            $this->clearDownloadedAssets($fileIds, syncIndex: $autoColumn === 'auto_blacklisted');
        }, idColumn: 'file_id');
    }

    private function renameAutoDislikedColumn(bool $allowCopyDdl): void
    {
        if (Schema::hasColumn('files', 'auto_blacklisted')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $lastException = null;
            $onlineRenameStatements = [
                'ALTER TABLE files RENAME COLUMN auto_disliked TO auto_blacklisted, ALGORITHM=INPLACE, LOCK=NONE',
                'ALTER TABLE files CHANGE auto_disliked auto_blacklisted TINYINT(1) NOT NULL DEFAULT 0, ALGORITHM=INPLACE, LOCK=NONE',
            ];

            foreach ($onlineRenameStatements as $statement) {
                try {
                    DB::statement($statement);

                    return;
                } catch (\Illuminate\Database\QueryException $e) {
                    $lastException = $e;
                }
            }

            if ($lastException === null) {
                return;
            }

            if (! $allowCopyDdl || ! $this->isUnsupportedOnlineDdl($lastException)) {
                throw $lastException;
            }

            $this->warn('Online column rename is unsupported for this files table. Falling back to ALGORITHM=COPY because --allow-copy-ddl was passed.');
            DB::statement('ALTER TABLE files CHANGE auto_disliked auto_blacklisted TINYINT(1) NOT NULL DEFAULT 0, ALGORITHM=COPY');

            return;
        }

        Schema::table('files', function (Blueprint $table): void {
            $table->renameColumn('auto_disliked', 'auto_blacklisted');
        });
    }

    private function isUnsupportedOnlineDdl(\Illuminate\Database\QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'algorithm=inplace is not supported')
            || str_contains($message, 'try algorithm=copy')
            || str_contains($message, '1846');
    }

    private function syncLocalIndexes(int $chunk): void
    {
        $this->info('Syncing local browse indexes for blacklisted rows...');

        $syncService = app(LocalBrowseIndexSyncService::class);

        $this->chunkFileIds(
            DB::table('files')->select('id')->whereNotNull('blacklisted_at'),
            $chunk,
            function (array $fileIds) use ($syncService): void {
                try {
                    $syncService->syncFilesByIds($fileIds);
                    $syncService->syncReactionsForFileIds($fileIds);
                } catch (\Throwable $e) {
                    $this->warn('Local browse index sync failed for chunk ending at file '.$fileIds[array_key_last($fileIds)].': '.$e->getMessage());
                }
            },
        );
    }

    private function clearDownloadedAssets(array $fileIds, bool $syncIndex): void
    {
        $files = File::query()
            ->select(['id', 'path', 'preview_path', 'poster_path', 'downloaded', 'downloaded_at', 'download_progress'])
            ->whereIn('id', $fileIds)
            ->get();

        app(DownloadedFileClearService::class)->clearMany($files, queueDelete: true, syncIndex: $syncIndex);
    }

    private function autoOriginFileQuery(string $autoColumn): \Illuminate\Database\Query\Builder
    {
        return DB::table('files')
            ->select('id')
            ->where($autoColumn, true);
    }

    private function dislikeReactionFileQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('reactions')
            ->select('file_id')
            ->where('type', 'dislike')
            ->distinct();
    }

    private function countActionTypeRows(string $table): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return DB::table($table)->where('action_type', 'dislike')->count();
    }

    /**
     * @param  callable(array<int>): void  $callback
     */
    private function chunkFileIds(
        \Illuminate\Database\Query\Builder $baseQuery,
        int $chunk,
        callable $callback,
        string $idColumn = 'id',
    ): void {
        $afterId = 0;

        while (true) {
            /** @var Collection<int, int> $ids */
            $ids = (clone $baseQuery)
                ->where($idColumn, '>', $afterId)
                ->orderBy($idColumn)
                ->limit($chunk)
                ->pluck($idColumn)
                ->map(fn (mixed $id): int => (int) $id);

            if ($ids->isEmpty()) {
                return;
            }

            $fileIds = $ids->values()->all();
            $callback($fileIds);
            $afterId = max($fileIds);
        }
    }
}
