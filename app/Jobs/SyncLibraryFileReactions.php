<?php

namespace App\Jobs;

use App\Services\Library\LibraryIndexSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncLibraryFileReactions implements ShouldQueue
{
    use Queueable;

    /**
     * @var array<int, int>
     */
    public array $fileIds;

    public int $tries = 3;

    /**
     * @param  array<int, int>  $fileIds
     */
    public function __construct(array $fileIds)
    {
        $this->fileIds = $this->normalizeIds($fileIds);
        $this->onQueue((string) config('library.typesense.reaction_sync_queue', 'library-reaction-sync'));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(LibraryIndexSyncService $syncService): void
    {
        if ($this->fileIds === []) {
            return;
        }

        $syncService->syncReactionsForFileIds($this->fileIds);
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', array_filter($ids, fn ($id) => is_numeric($id)))));
    }
}
