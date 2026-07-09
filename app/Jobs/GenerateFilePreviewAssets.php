<?php

namespace App\Jobs;

use App\Enums\MediaProcessorTaskStatus;
use App\Events\FilePreviewAssetsUpdated;
use App\Models\File;
use App\Services\Downloads\FileDownloadFinalizer;
use App\Services\FilePreviewOriginalHealthService;
use App\Services\FilePreviewRepairService;
use App\Support\FilePreviewGeneration;
use Carbon\CarbonImmutable;
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

    public function handle(
        FileDownloadFinalizer $finalizer,
        ?FilePreviewOriginalHealthService $originalHealth = null,
        ?FilePreviewRepairService $previewRepair = null,
    ): void {
        $originalHealth ??= app(FilePreviewOriginalHealthService::class);
        $previewRepair ??= app(FilePreviewRepairService::class);

        $previewMemoryLimit = (string) config('downloads.preview_php_memory_limit', '');
        if ($previewMemoryLimit !== '') {
            @ini_set('memory_limit', $previewMemoryLimit);
        }

        $file = File::query()->find($this->fileId);
        if (! $file) {
            return;
        }

        $startedAt = CarbonImmutable::now()->subSecond();
        $updates = $finalizer->generatePreviewAssets($file, $this->force);
        if ($updates === []) {
            if ($this->hasActivePreviewTask($file, $startedAt)) {
                event(new FilePreviewAssetsUpdated((int) $file->id));

                return;
            }

            $health = $originalHealth->inspect($file);
            if (! $health['healthy']) {
                $previewRepair->repairUnhealthyOriginal($file, $health, null);
            }

            return;
        }

        $file->update($updates);
        event(new FilePreviewAssetsUpdated((int) $file->id));
    }

    private function hasActivePreviewTask(File $file, CarbonImmutable $startedAt): bool
    {
        return $file->mediaProcessorTasks()
            ->whereIn('operation', FilePreviewGeneration::operations())
            ->whereIn('status', MediaProcessorTaskStatus::active())
            ->where('created_at', '>=', $startedAt)
            ->exists();
    }
}
