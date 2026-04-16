<?php

namespace App\Jobs;

use App\Models\File;
use App\Services\Downloads\FileDownloadFinalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RegenerateVideoPreviewAssets implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public int $fileId)
    {
        $this->onQueue('maintenance');
    }

    public function handle(FileDownloadFinalizer $finalizer): void
    {
        $previewMemoryLimit = (string) config('downloads.preview_php_memory_limit', '');
        if ($previewMemoryLimit !== '') {
            @ini_set('memory_limit', $previewMemoryLimit);
        }

        $file = File::query()->find($this->fileId);
        if (! $file) {
            return;
        }

        $updates = $finalizer->regenerateVideoPreviewAssets($file);
        if ($updates === []) {
            return;
        }

        $file->update($updates);
    }
}
