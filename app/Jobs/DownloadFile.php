<?php

namespace App\Jobs;

use App\Events\FileDownloadProgress;
use App\Models\File;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class DownloadFile implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public $file;

    /**
     * Create a new job instance.
     */
    public function __construct(File $file)
    {
        $this->file = $file;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $fileUrl = $this->file->url;
            $filePath = 'downloads/' . $this->file->filename;


            // Initialize progress
            $this->file->update(['download_progress' => 0]);
            event(new FileDownloadProgress($this->file->id, 0));

            $tempFile = tempnam(sys_get_temp_dir(), 'download_');

            // Download with progress tracking
            $response = Http::withOptions([
                'sink' => $tempFile,
                'progress' => function ($downloadTotal, $downloadedBytes) {
                    if ($downloadTotal > 0) {
                        $progress = round(($downloadedBytes / $downloadTotal) * 100);
                        $lastReportedProgress = $this->file->download_progress ?? 0;
                        
                        // Only emit progress events at 5% intervals
                        if ($progress >= $lastReportedProgress + 5 || $progress === 100) {
                            $this->file->update(['download_progress' => $progress]);
                            event(new FileDownloadProgress($this->file->id, $progress));
                        }
                    }
                }
            ])->timeout(300)->get($fileUrl); // 5 minute timeout

            if (!$response->successful()) {
                throw new \Exception("Failed to download file: HTTP {$response->status()}");
            }

            // Ensure we have the file
            if (!file_exists($tempFile) || filesize($tempFile) === 0) {
                throw new \Exception("Downloaded file is empty or doesn't exist");
            }

            // Store the file in the 'atlas' disk under downloads folder
            Storage::disk('atlas')->put($filePath, file_get_contents($tempFile));

            // Clean up temp file
            unlink($tempFile);

            // Final progress update
            event(new FileDownloadProgress($this->file->id, 100));

            // Update file metadata
            $this->file->update([
                'path' => $filePath,
                'downloaded' => true,
                'downloaded_at' => now(),
                'download_progress' => 100
            ]);

            
        } catch (\Exception $e) {
            
            // Update file with error status
            $this->file->update([
                'download_progress' => 0,
                'downloaded' => false
            ]);
            
            // Emit failure event
            event(new FileDownloadProgress($this->file->id, -1)); // -1 indicates error
            
            throw $e; // Re-throw to mark job as failed
        }
    }
}
