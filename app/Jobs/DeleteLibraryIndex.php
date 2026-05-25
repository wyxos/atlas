<?php

namespace App\Jobs;

use App\Services\Library\LibraryIndexSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeleteLibraryIndex implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue((string) config('library.typesense.delete_queue', 'library-delete'));
    }

    public function handle(LibraryIndexSyncService $syncService): void
    {
        $syncService->deleteAll();
    }
}
