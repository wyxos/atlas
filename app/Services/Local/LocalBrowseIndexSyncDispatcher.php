<?php

namespace App\Services\Local;

use App\Jobs\SyncLocalBrowseIndex;

class LocalBrowseIndexSyncDispatcher
{
    /**
     * @param  array<int, int>  $fileIds
     */
    public function files(array $fileIds): void
    {
        $this->dispatch($fileIds, syncFiles: true, syncReactions: false);
    }

    /**
     * @param  array<int, int>  $fileIds
     */
    public function filesAndReactions(array $fileIds): void
    {
        $this->dispatch($fileIds, syncFiles: true, syncReactions: true);
    }

    /**
     * @param  array<int, int>  $fileIds
     */
    private function dispatch(array $fileIds, bool $syncFiles, bool $syncReactions): void
    {
        $fileIds = $this->normalizeIds($fileIds);

        if ($fileIds === [] || (! $syncFiles && ! $syncReactions)) {
            return;
        }

        foreach (array_chunk($fileIds, $this->chunkSize()) as $chunk) {
            SyncLocalBrowseIndex::dispatch($chunk, $syncFiles, $syncReactions)->afterCommit();
        }
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', array_filter($ids, fn ($id) => is_numeric($id)))));
    }

    private function chunkSize(): int
    {
        return max(1, (int) config('local_browse.typesense.chunk', 500));
    }
}
