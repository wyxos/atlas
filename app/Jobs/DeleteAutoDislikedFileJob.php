<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Delete local file from disk after it has been auto-disliked or blacklisted.
 * Only deletes files that have a local path (downloaded files).
 * File records are preserved in the database.
 *
 * This job runs asynchronously to avoid blocking the browse response.
 */
class DeleteAutoDislikedFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string|array $filePath,
        public array $diskNames = []
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $paths = is_array($this->filePath) ? $this->filePath : [$this->filePath];
        $paths = array_values(array_unique(array_filter($paths, static fn (mixed $path): bool => is_string($path) && $path !== '')));

        if ($paths === []) {
            return;
        }

        $diskNames = $this->diskNames !== []
            ? $this->diskNames
            : array_values(array_unique(array_filter([
                (string) config('downloads.disk'),
                'atlas',
            ], static fn (mixed $diskName): bool => is_string($diskName) && $diskName !== '')));

        foreach ($diskNames as $diskName) {
            foreach ($paths as $path) {
                try {
                    $disk = Storage::disk($diskName);
                    if ($disk->exists($path)) {
                        $disk->delete($path);
                    }
                } catch (\Throwable $e) {
                    // Log but don't fail the job - file might already be deleted
                    // or disk might not be configured
                    \Illuminate\Support\Facades\Log::debug("DeleteAutoDislikedFileJob: Could not delete file on disk {$diskName}: {$e->getMessage()}");
                }
            }
        }
    }
}
