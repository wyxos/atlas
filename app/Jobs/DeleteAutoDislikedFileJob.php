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
        public string $filePath,
        public array $diskNames = ['atlas-app', 'atlas']
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (empty($this->filePath)) {
            return;
        }

        foreach ($this->diskNames as $diskName) {
            try {
                $disk = Storage::disk($diskName);
                if ($disk->exists($this->filePath)) {
                    $disk->delete($this->filePath);
                }
            } catch (\Throwable $e) {
                // Log but don't fail the job - file might already be deleted
                // or disk might not be configured
                \Illuminate\Support\Facades\Log::debug("DeleteAutoDislikedFileJob: Could not delete file on disk {$diskName}: {$e->getMessage()}");
            }
        }
    }
}
