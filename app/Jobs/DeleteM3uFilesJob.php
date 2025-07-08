<?php

namespace App\Jobs;

use App\Models\File;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class DeleteM3uFilesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600; // 1 hour timeout
    public int $tries = 3;

    public int $chunkSize;
    private int $deletedCount = 0;
    private int $errorCount = 0;

    /**
     * Create a new job instance.
     */
    public function __construct(int $chunkSize = 100)
    {
        $this->chunkSize = $chunkSize;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Find all files with m3u extension or mime type
            $query = File::where(function ($q) {
                $q->where('ext', 'like', '%m3u%')
                  ->orWhere('mime_type', 'like', '%m3u%')
                  ->orWhere('mime_type', 'like', '%mpegurl%')
                  ->orWhere('filename', 'like', '%.m3u%');
            });

            $totalFiles = $query->count();

            if ($totalFiles === 0) {
                return;
            }

            // Process files in chunks to avoid memory issues
            $query->chunkById($this->chunkSize, function ($files) {
                foreach ($files as $file) {
                    try {
                        $this->deleteFile($file);
                        $this->deletedCount++;
                    } catch (\Exception $e) {
                        $this->errorCount++;
                        // Continue processing other files even if one fails
                    }
                }
            });

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Delete a single file from disk and database.
     */
    private function deleteFile(File $file): void
    {
        // Delete from disk if path exists
        if ($file->path) {
            try {
                // Check if it's a URL or local file
                if (!filter_var($file->path, FILTER_VALIDATE_URL)) {
                    // Try to delete from atlas disk first
                    if (Storage::disk('atlas')->exists($file->path)) {
                        Storage::disk('atlas')->delete($file->path);
                    } elseif (file_exists($file->path)) {
                        // Try direct file path
                        unlink($file->path);
                    }
                }
            } catch (\Exception $e) {
                // Continue with database deletion even if disk deletion fails
            }
        }

        // Delete related records first (due to foreign key constraints)
        $file->artists()->detach();
        $file->albums()->detach();
        $file->playlists()->detach();
        $file->covers()->delete();

        // Delete metadata if exists
        if ($file->metadata) {
            $file->metadata->delete();
        }

        // Finally delete the file record
        $file->delete();
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return ['m3u-deletion', 'file-cleanup'];
    }
}
