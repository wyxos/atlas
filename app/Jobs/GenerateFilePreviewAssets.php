<?php

namespace App\Jobs;

use App\Models\File;
use App\Services\Downloads\FileDownloadFinalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateFilePreviewAssets implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $force = false;

    public function __construct(public int $fileId, bool $force = false)
    {
        $this->force = $force;
        $this->onQueue('processing');
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

        $updates = $finalizer->generatePreviewAssets($file, $this->force);
        if ($updates === []) {
            return;
        }

        $file->update($updates);
    }
}
