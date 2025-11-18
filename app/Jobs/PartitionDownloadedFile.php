<?php

namespace App\Jobs;

use App\Models\File;
use App\Support\PartitionedPathHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PartitionDownloadedFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $fileId,
        public int $subdirLength = 2
    ) {}

    public function handle(): void
    {
        $file = File::query()->find($this->fileId);
        if (! $file || ! $file->path || ! $file->downloaded) {
            return;
        }

        // Get the filename from path
        $filename = basename($file->path);
        if ($filename === '' || $filename === $file->path) {
            // Path doesn't have a directory separator, use filename field
            $filename = $file->filename ?? Str::random(40);
        }

        // Generate partitioned path
        $newPath = PartitionedPathHelper::generatePath($filename, $this->subdirLength);

        // Check if file already exists at new location (already partitioned)
        if ($file->path === $newPath) {
            return;
        }

        // Find which disks have the file
        $disksWithFile = collect(['atlas_app', 'atlas'])->filter(function (string $disk) use ($file) {
            return Storage::disk($disk)->exists($file->path);
        })->values();

        if ($disksWithFile->isEmpty()) {
            Log::warning('PartitionDownloadedFile: file missing on all disks', [
                'file_id' => $file->id,
                'path' => $file->path,
            ]);

            return;
        }

        // Move file on each disk
        foreach ($disksWithFile as $disk) {
            $storage = Storage::disk($disk);

            // Ensure subdirectory exists
            $subdir = PartitionedPathHelper::getSubdirectory($filename, $this->subdirLength);
            $subdirPath = "downloads/{$subdir}";
            if (! $storage->exists($subdirPath)) {
                $storage->makeDirectory($subdirPath);
            }

            // Move the file
            if (! $storage->move($file->path, $newPath)) {
                throw new \RuntimeException("Failed to move file on disk {$disk} from {$file->path} to {$newPath}");
            }
        }

        // Update database record
        $file->update(['path' => $newPath]);

        // Update search index
        try {
            $file->refresh();
            $file->searchable();
        } catch (\Throwable $e) {
            Log::warning('PartitionDownloadedFile: searchable failed', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
