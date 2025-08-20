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

            // Probe server for range support and filesize
            $acceptRanges = null;
            $contentLength = null;

            try {
                $head = Http::timeout(15)->head($fileUrl);
                if ($head->ok()) {
                    $acceptRanges = $head->header('Accept-Ranges');
                    $contentLength = (int) $head->header('Content-Length');
                }
            } catch (\Throwable $e) {
                // Some servers do not support HEAD; we'll fallback to range probe below
            }

            if (!$acceptRanges || strtolower($acceptRanges) !== 'bytes' || !$contentLength) {
                // Try a 1-byte range probe to detect support and size via Content-Range
                try {
                    $probe = Http::withHeaders(['Range' => 'bytes=0-0'])->timeout(30)->get($fileUrl);
                    if ($probe->status() === 206) {
                        $acceptRanges = 'bytes';
                        $contentRange = $probe->header('Content-Range'); // e.g., bytes 0-0/12345
                        if ($contentRange && preg_match('/\/(\d+)$/', $contentRange, $m)) {
                            $contentLength = (int) $m[1];
                        }
                    }
                } catch (\Throwable $e) {
                    // Ignore, will fallback to full GET
                }
            }

            $usedRangeDownload = false;

            if ($acceptRanges === 'bytes' && $contentLength && $contentLength > 0) {
                // Segmented download using Range requests
                $chunkSize = 4 * 1024 * 1024; // 4 MB per chunk
                $total = $contentLength;
                $chunks = (int) ceil($total / $chunkSize);
                $downloadedSoFar = 0;

                // Pre-create file
                $fp = fopen($tempFile, 'c+b');
                if ($fp === false) {
                    throw new \Exception('Unable to open temp file for writing');
                }
                // Optionally set file size (not strictly necessary)
                ftruncate($fp, $total);

                for ($i = 0; $i < $chunks; $i++) {
                    $start = $i * $chunkSize;
                    $end = min($start + $chunkSize - 1, $total - 1);
                    $length = ($end - $start + 1);

                    // Move pointer to correct offset before writing this chunk
                    if (fseek($fp, $start) !== 0) {
                        fclose($fp);
                        throw new \Exception('Failed to seek in temp file');
                    }

                    $lastReportedProgress = $this->file->download_progress ?? 0;

                    $response = Http::withHeaders([
                        'Range' => "bytes={$start}-{$end}",
                    ])->withOptions([
                        'sink' => $fp,
                        'progress' => function ($chunkTotal, $downloadedBytes) use (&$downloadedSoFar, $total, $lastReportedProgress) {
                            // $chunkTotal is the length of this chunk (may be 0 if unknown)
                            $currentOverall = $downloadedSoFar + (int) $downloadedBytes;
                            if ($total > 0) {
                                $progress = (int) round(($currentOverall / $total) * 100);
                                if ($progress >= $lastReportedProgress + 5 || $progress === 100) {
                                    $this->file->update(['download_progress' => $progress]);
                                    event(new FileDownloadProgress($this->file->id, $progress));
                                }
                            }
                        }
                    ])->timeout(300)->get($fileUrl);

                    if ($response->status() !== 206) {
                        // Server did not honor range; fallback to full GET
                        fclose($fp);
                        $usedRangeDownload = false;
                        break;
                    }

                    $downloadedSoFar += $length;
                    $usedRangeDownload = true;
                }

                if ($usedRangeDownload) {
                    fclose($fp);
                }
            }

            if (!$usedRangeDownload) {
                // Fallback: single request download with progress tracking
                $response = Http::withOptions([
                    'sink' => $tempFile,
                    'progress' => function ($downloadTotal, $downloadedBytes) {
                        if ($downloadTotal > 0) {
                            $progress = (int) round(($downloadedBytes / $downloadTotal) * 100);
                            $lastReportedProgress = $this->file->download_progress ?? 0;
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
