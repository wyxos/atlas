<?php

namespace App\Services\Library;

use App\Jobs\ReindexLibraryTypesense;
use App\Models\LibraryReindexRun;
use App\Models\Reaction;
use App\Models\Search\LibraryFileDocument;

class LibraryReindexService
{
    public function __construct(
        private LibraryIndexSyncService $syncService,
        private LibraryTypesenseNames $names,
    ) {}

    public function activeRun(): ?LibraryReindexRun
    {
        return LibraryReindexRun::query()
            ->whereIn('status', LibraryReindexRun::activeStatuses())
            ->latest()
            ->first();
    }

    /**
     * @return array{0: LibraryReindexRun, 1: bool}
     */
    public function queue(?string $suffix = null): array
    {
        $activeRun = $this->activeRun();
        if ($activeRun) {
            return [$activeRun, false];
        }

        $run = $this->createRun($suffix);
        ReindexLibraryTypesense::dispatch($run->id);

        return [$run, true];
    }

    public function createRun(?string $suffix = null): LibraryReindexRun
    {
        return LibraryReindexRun::query()->create([
            'status' => LibraryReindexRun::STATUS_PENDING,
            'phase' => 'queued',
            'suffix' => $suffix ?: now()->utc()->format('Ymd_His'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function run(LibraryReindexRun $run, ?callable $progress = null): array
    {
        if ($run->isTerminal()) {
            return $this->summary($run);
        }

        $filesTotal = (int) LibraryFileDocument::query()->count();
        $reactionsTotal = (int) Reaction::query()->count();

        $run->update([
            'status' => LibraryReindexRun::STATUS_RUNNING,
            'phase' => 'files',
            'files_alias' => $this->names->filesAlias(),
            'files_collection' => $this->names->filesCollection($run->suffix),
            'reactions_alias' => $this->names->reactionsAlias(),
            'reactions_collection' => $this->names->reactionsCollection($run->suffix),
            'files_total' => $filesTotal,
            'files_indexed' => 0,
            'reactions_total' => $reactionsTotal,
            'reactions_indexed' => 0,
            'started_at' => $run->started_at ?? now(),
            'finished_at' => null,
            'error' => null,
        ]);

        try {
            $summary = $this->syncService->rebuild($run->suffix, function (string $type, int $count) use ($run, $progress): void {
                $column = $type === 'reactions' ? 'reactions_indexed' : 'files_indexed';
                $phase = $type === 'reactions' ? 'reactions' : 'files';

                $run->increment($column, $count, [
                    'phase' => $phase,
                    'updated_at' => now(),
                ]);

                if ($progress) {
                    $progress($type, $count, $run->fresh());
                }
            });

            $run->update([
                'status' => LibraryReindexRun::STATUS_COMPLETED,
                'phase' => 'completed',
                'files_alias' => $summary['files_alias'],
                'files_collection' => $summary['files_collection'],
                'reactions_alias' => $summary['reactions_alias'],
                'reactions_collection' => $summary['reactions_collection'],
                'files_total' => (int) $summary['files_total'],
                'files_indexed' => (int) $summary['files_total'],
                'reactions_total' => (int) $summary['reactions_total'],
                'reactions_indexed' => (int) $summary['reactions_total'],
                'finished_at' => now(),
                'error' => null,
            ]);

            return $this->summary($run->fresh());
        } catch (\Throwable $e) {
            $run->update([
                'status' => LibraryReindexRun::STATUS_FAILED,
                'phase' => 'failed',
                'finished_at' => now(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(LibraryReindexRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'phase' => $run->phase,
            'suffix' => $run->suffix,
            'files_alias' => $run->files_alias,
            'files_collection' => $run->files_collection,
            'reactions_alias' => $run->reactions_alias,
            'reactions_collection' => $run->reactions_collection,
            'files_total' => $run->files_total,
            'files_indexed' => $run->files_indexed,
            'reactions_total' => $run->reactions_total,
            'reactions_indexed' => $run->reactions_indexed,
            'started_at' => $run->started_at?->toISOString(),
            'finished_at' => $run->finished_at?->toISOString(),
            'error' => $run->error,
        ];
    }
}
