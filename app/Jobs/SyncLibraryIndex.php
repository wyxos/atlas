<?php

namespace App\Jobs;

use App\Services\Library\LibraryIndexSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncLibraryIndex implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var array<int, int>
     */
    public array $fileIds;

    public int $tries = 3;

    /**
     * @param  array<int, int>  $fileIds
     */
    public function __construct(
        array $fileIds,
        public bool $syncFiles = true,
        public bool $syncReactions = false,
        public string $operation = 'sync',
    ) {
        $this->fileIds = $this->normalizeIds($fileIds);
        $this->onQueue((string) config('library.typesense.sync_queue', 'library-sync'));
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
        if ($this->operation === 'deleteAll') {
            $syncService->deleteAll();

            return;
        }

        if ($this->operation === 'deleteFiles') {
            if ($this->fileIds !== []) {
                $syncService->deleteFilesByIds($this->fileIds);
            }

            return;
        }

        if ($this->fileIds === [] || (! $this->syncFiles && ! $this->syncReactions)) {
            return;
        }

        if ($this->syncFiles) {
            $syncService->syncFilesByIds($this->fileIds);
        }

        if ($this->syncReactions) {
            $syncService->syncReactionsForFileIds($this->fileIds);
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
}
