<?php

namespace App\Jobs;

use App\Enums\MediaProcessorOperation;
use App\Enums\MediaProcessorTaskStatus;
use App\Models\File;
use App\Services\LibraryScans\LibraryScanMediaProcessor;
use App\Support\AtlasPathResolver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateFileStreamableVideo implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $fileId)
    {
        $this->onQueue('processing');
    }

    public function uniqueId(): string
    {
        return (string) $this->fileId;
    }

    public function handle(LibraryScanMediaProcessor $processor): void
    {
        $file = File::query()->with('metadata')->find($this->fileId);
        if (! $file || ! $file->path) {
            return;
        }

        $streamablePath = data_get($file->metadata?->payload, 'conversions.streamable_video');
        if (is_string($streamablePath) && AtlasPathResolver::resolveExistingPath($streamablePath)) {
            return;
        }

        $hasActiveTask = $file->mediaProcessorTasks()
            ->where('operation', MediaProcessorOperation::STREAMABLE_VIDEO)
            ->whereIn('status', MediaProcessorTaskStatus::active())
            ->exists();

        if ($hasActiveTask) {
            return;
        }

        $processor->createStreamableVideo($file);
    }
}
