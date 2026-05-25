<?php

namespace App\Services\Library;

use App\Jobs\DeleteLibraryFiles;
use App\Jobs\DeleteLibraryIndex;
use App\Jobs\SyncLibraryFileReactions;
use App\Jobs\SyncLibraryFiles;

class LibraryIndexSyncDispatcher
{
    /**
     * @param  array<int, int>  $fileIds
     */
    public function files(array $fileIds): void
    {
        $this->dispatchFiles($fileIds);
    }

    /**
     * @param  array<int, int>  $fileIds
     */
    public function filesAndReactions(array $fileIds): void
    {
        $this->dispatchFiles($fileIds);
        $this->dispatchReactions($fileIds);
    }

    /**
     * @param  array<int, int>  $fileIds
     */
    public function deleteFiles(array $fileIds): void
    {
        $fileIds = $this->normalizeIds($fileIds);

        if ($fileIds === []) {
            return;
        }

        foreach (array_chunk($fileIds, $this->chunkSize()) as $chunk) {
            DeleteLibraryFiles::dispatch($chunk)->afterCommit();
        }
    }

    public function deleteAll(): void
    {
        DeleteLibraryIndex::dispatch()->afterCommit();
    }

    /**
     * @param  array<int, int>  $fileIds
     */
    private function dispatchFiles(array $fileIds): void
    {
        $fileIds = $this->normalizeIds($fileIds);

        if ($fileIds === []) {
            return;
        }

        foreach (array_chunk($fileIds, $this->chunkSize()) as $chunk) {
            SyncLibraryFiles::dispatch($chunk)->afterCommit();
        }
    }

    /**
     * @param  array<int, int>  $fileIds
     */
    private function dispatchReactions(array $fileIds): void
    {
        $fileIds = $this->normalizeIds($fileIds);

        if ($fileIds === []) {
            return;
        }

        foreach (array_chunk($fileIds, $this->chunkSize()) as $chunk) {
            SyncLibraryFileReactions::dispatch($chunk)->afterCommit();
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
        return max(1, (int) config('library.typesense.chunk', 500));
    }
}
