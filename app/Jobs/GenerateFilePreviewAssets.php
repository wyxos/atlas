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

    public function __construct(public int $fileId)
    {
        $this->onQueue('processing');
    }

    public function handle(FileDownloadFinalizer $finalizer): void
    {
        $file = File::query()->find($this->fileId);
        if (! $file) {
            return;
        }

        $updates = $finalizer->generatePreviewAssets($file);
        if ($updates === []) {
            return;
        }

        $file->update($updates);
    }
}
