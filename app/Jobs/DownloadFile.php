<?php

namespace App\Jobs;

use App\Events\DownloadCreated;
use App\Events\FileDownloadProgress;
use App\Models\Download;
use App\Models\File;
use App\Support\CivitaiVideoUrlExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;

class DownloadFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public File $file;

    public ?int $downloadId = null;

    public function __construct(File $file)
    {
        $this->file = $file;
    }

    public function handle(): void
    {
        try {
            $fileUrl = (string) $this->file->url;

            if ($fileUrl === '' || ! filter_var($fileUrl, FILTER_VALIDATE_URL)) {
                $candidateUrls = [
                    data_get($this->file->listing_metadata, 'url'),
                    data_get($this->file->detail_metadata, 'url'),
                    data_get($this->file->detail_metadata, 'downloadUrl'),
                ];

                foreach ($candidateUrls as $candidate) {
                    if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_URL)) {
                        $fileUrl = $candidate;
                        $this->file->update([
                            'url' => $candidate,
                            'not_found' => false,
                        ]);

                        break;
                    }
                }
            }

            if (! filter_var($fileUrl, FILTER_VALIDATE_URL)) {
                throw new \RuntimeException('File URL is invalid and no fallback could be determined');
            }

            $filename = $this->determineInitialFilename($fileUrl);
            $filePath = 'downloads/'.$filename;

            $resolvedMime = null;

            // Initialize progress
            $this->file->update(['download_progress' => 0]);
            event(new FileDownloadProgress($this->file->id, 0, 0, null, 'in-progress'));

            // If user explicitly downloads, clear blacklist flags (explicit intent overrides previous blacklist)
            if ($this->file->blacklisted_at !== null) {
                $this->file->update([
                    'blacklisted_at' => null,
                    'blacklist_reason' => null,
                ]);
            }

            // Create a Download row for tracking
            $download = Download::create([
                'file_id' => $this->file->id,
                'status' => 'in-progress',
                'progress' => 0,
                'bytes_downloaded' => 0,
                'bytes_total' => null,
                'job_id' => method_exists($this, 'job') && $this->job ? (string) $this->job->getJobId() : null,
                'started_at' => now(),
            ]);
            $this->downloadId = $download->id;
            event(new DownloadCreated($download->id, $this->file->id));

            $tempFile = tempnam(sys_get_temp_dir(), 'download_');

            // Probe server for range support and filesize
            $acceptRanges = null;
            $contentLength = null;

            // Check for CivitAI video mislabeling issue before downloading
            $fileUrl = $this->checkCivitaiVideoUrl($fileUrl);

            try {
                $head = Http::timeout(15)->head($fileUrl);
                if ($head->status() === 404) {
                    $this->file->update(['not_found' => true]);
                    if ($this->downloadId) {
                        Download::where('id', $this->downloadId)->update([
                            'status' => 'failed',
                            'error' => '404 Not Found',
                        ]);

                        return;
                    }
                }
                if ($head->ok()) {
                    $resolvedMime = $head->header('Content-Type') ?: $resolvedMime;
                    $acceptRanges = $head->header('Accept-Ranges');
                    $contentLength = (int) $head->header('Content-Length');
                    if ($this->downloadId && $contentLength) {
                        Download::where('id', $this->downloadId)->update([
                            'bytes_total' => $contentLength,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                // Some servers do not support HEAD; we'll fallback to range probe below
            }

            if (! $acceptRanges || strtolower((string) $acceptRanges) !== 'bytes' || ! $contentLength) {
                // Try a 1-byte range probe to detect support and size via Content-Range
                try {
                    $probe = Http::withHeaders(['Range' => 'bytes=0-0'])->timeout(30)->get($fileUrl);
                    if ($probe->status() === 404) {
                        $this->file->update(['not_found' => true]);
                        if ($this->downloadId) {
                            Download::where('id', $this->downloadId)->update([
                                'status' => 'failed',
                                'error' => '404 Not Found',
                            ]);
                        }

                        return;
                    }
                    if ($probe->status() === 206) {
                        $acceptRanges = 'bytes';
                        $probeMime = $probe->header('Content-Type');
                        $resolvedMime = $probeMime ?: $resolvedMime;

                        // Check for CivitAI video mislabeling issue if HEAD didn't catch it
                        if ($probeMime) {
                            $fileUrl = $this->checkCivitaiVideoUrlFromMime($fileUrl, strtolower((string) $probeMime));
                        }

                        $contentRange = $probe->header('Content-Range'); // e.g., bytes 0-0/12345
                        if ($contentRange && preg_match('/\/(\d+)$/', (string) $contentRange, $m)) {
                            $contentLength = (int) $m[1];
                            if ($this->downloadId && $contentLength) {
                                Download::where('id', $this->downloadId)->update([
                                    'bytes_total' => $contentLength,
                                ]);
                            }
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
                    // Cancellation/pause check between chunks
                    if ($this->downloadId) {
                        $row = Download::find($this->downloadId);
                        if ($row && $row->cancel_requested_at) {
                            // Respect paused state if set by controller
                            if ($row->status === 'paused') {
                                fclose($fp);

                                return; // graceful exit, keep status as paused
                            }
                            fclose($fp);
                            throw new \RuntimeException('Download canceled');
                        }
                    }

                    $start = $i * $chunkSize;
                    $end = min($start + $chunkSize - 1, $total - 1);
                    $length = ($end - $start + 1);

                    // Move pointer to correct offset before writing this chunk
                    if (fseek($fp, $start) !== 0) {
                        fclose($fp);
                        throw new \Exception('Failed to seek in temp file');
                    }

                    $lastReportedProgress = (int) ($this->file->download_progress ?? 0);

                    $response = Http::withHeaders([
                        'Range' => "bytes={$start}-{$end}",
                    ])->withOptions([
                        'sink' => $fp,
                        'progress' => function ($chunkTotal, $downloadedBytes) use (&$downloadedSoFar, $total, $lastReportedProgress) {
                            $currentOverall = $downloadedSoFar + (int) $downloadedBytes;
                            if ($total > 0) {
                                $progress = (int) round(($currentOverall / $total) * 100);
                                if ($progress >= $lastReportedProgress + 5 || $progress === 100) {
                                    event(new FileDownloadProgress($this->file->id, $progress, $currentOverall, $total));
                                    if ($this->downloadId) {
                                        Download::where('id', $this->downloadId)->update([
                                            'progress' => $progress,
                                            'bytes_downloaded' => $currentOverall,
                                        ]);
                                    }
                                }
                                // Cancellation/pause check inside callback
                                if ($this->downloadId) {
                                    $row = Download::find($this->downloadId);
                                    if ($row && $row->cancel_requested_at) {
                                        throw new \RuntimeException('Download canceled');
                                    }
                                }
                            }
                        },
                    ])->timeout(300)->get($fileUrl);

                    if ($response->status() === 404) {
                        fclose($fp);
                        $this->file->update(['not_found' => true]);
                        if ($this->downloadId) {
                            Download::where('id', $this->downloadId)->update([
                                'status' => 'failed',
                                'error' => '404 Not Found',
                            ]);
                        }
                        throw new \Exception('Remote file not found (404)');
                    }
                    if ($response->status() !== 206) {
                        // Server did not honor range; fallback to full GET
                        fclose($fp);
                        $usedRangeDownload = false;
                        break;
                    }

                    $downloadedSoFar += $length;
                    $resolvedMime = $response->header('Content-Type') ?: $resolvedMime;
                    if ($this->downloadId) {
                        Download::where('id', $this->downloadId)->update([
                            'bytes_downloaded' => $downloadedSoFar,
                        ]);
                    }
                    $usedRangeDownload = true;
                }

                if ($usedRangeDownload) {
                    fclose($fp);
                }
            }

            if (! $usedRangeDownload) {
                // Fallback: single request download with progress tracking
                $response = Http::withOptions([
                    'sink' => $tempFile,
                    'progress' => function ($downloadTotal, $downloadedBytes) {
                        if ($downloadTotal > 0) {
                            $progress = (int) round(((int) $downloadedBytes / (int) $downloadTotal) * 100);
                            $lastReportedProgress = (int) ($this->file->download_progress ?? 0);
                            if ($progress >= $lastReportedProgress + 5 || $progress === 100) {
                                event(new FileDownloadProgress($this->file->id, $progress, (int) $downloadedBytes, (int) $downloadTotal));
                                if ($this->downloadId) {
                                    Download::where('id', $this->downloadId)->update([
                                        'progress' => $progress,
                                        'bytes_total' => $downloadTotal ?: null,
                                        'bytes_downloaded' => (int) $downloadedBytes,
                                    ]);
                                }
                            }
                            // Cancel/pause check
                            if ($this->downloadId) {
                                $row = Download::find($this->downloadId);
                                if ($row && $row->cancel_requested_at) {
                                    throw new \RuntimeException('Download canceled');
                                }
                            }
                        }
                    },
                ])->timeout(300)->get($fileUrl);

                if ($response->status() === 404) {
                    $this->file->update(['not_found' => true]);
                    throw new \Exception('Remote file not found (404)');
                }
                if (! $response->successful()) {
                    throw new \Exception('Failed to download file: HTTP '.$response->status());
                }

                if ($response->body() && (! file_exists($tempFile) || filesize($tempFile) === 0)) {
                    file_put_contents($tempFile, $response->body());
                }

                $resolvedMime = $response->header('Content-Type') ?: $resolvedMime;
            }

            // Ensure we have the file
            if (! file_exists($tempFile) || filesize($tempFile) === 0) {
                throw new \Exception("Downloaded file is empty or doesn't exist");
            }

            $sniffedMime = $this->detectMimeFromFile($tempFile);
            if (! $resolvedMime && $sniffedMime) {
                $resolvedMime = $sniffedMime;
            }

            if (strcasecmp((string) $this->file->source, 'CivitAI') === 0 && $sniffedMime) {
                if ($resolvedMime && str_contains((string) $resolvedMime, 'image/webp') && str_starts_with($sniffedMime, 'video/')) {
                    $resolvedMime = $sniffedMime;
                }
            }

            if (! $resolvedMime) {
                $resolvedMime = $this->file->mime_type;
            }

            [$filename, $filePath] = $this->adjustFilenameForMime($filename, $resolvedMime, $filePath);

            // Store the file in the 'atlas' disk under downloads folder
            Storage::disk('atlas_app')->put($filePath, file_get_contents($tempFile));

            // Clean up temp file
            @unlink($tempFile);

            // Final progress update
            event(new FileDownloadProgress($this->file->id, 100, $contentLength ?: null, $contentLength ?: null, 'completed'));
            if ($this->downloadId) {
                Download::where('id', $this->downloadId)->update([
                    'status' => 'completed',
                    'progress' => 100,
                    'bytes_downloaded' => $contentLength ?: null,
                    'completed_at' => now(),
                ]);
            }

            // Update file metadata
            $this->file->update([
                'path' => $filePath,
                'downloaded' => true,
                'downloaded_at' => now(),
                'download_progress' => 100,
                'filename' => $filename,
                'mime_type' => $resolvedMime ?: $this->file->mime_type,
            ]);

            // Ensure search index reflects local availability immediately
            $this->file->refresh();
            try {
                $this->file->searchable();
            } catch (\Throwable $e) {
                // ignore indexing errors
            }
        } catch (\Throwable $e) {
            // Update file with error status
            $this->file->update([
                'download_progress' => 0,
                'downloaded' => false,
            ]);

            // Update Download row depending on state
            if ($this->downloadId) {
                $row = Download::find($this->downloadId);
                if ($row) {
                    if ($row->status === 'paused') {
                        // Keep paused status; do not mark failed
                        // no-op
                    } elseif ($row->status === 'canceled') {
                        $row->update([
                            'canceled_at' => $row->canceled_at ?: now(),
                        ]);
                    } else {
                        $msg = trim(substr((string) $e->getMessage(), 0, 500));
                        $row->update([
                            'status' => 'failed',
                            'error' => $msg,
                        ]);
                    }
                }
            }

            // Emit failure event
            $status = null;
            if ($this->downloadId) {
                $row = \App\Models\Download::find($this->downloadId);
                $status = $row?->status;
            }
            event(new FileDownloadProgress($this->file->id, -1, null, null, $status === 'canceled' ? 'canceled' : ($status === 'paused' ? 'paused' : 'failed')));
            throw $e; // rethrow for failed job handling
        }
    }

    protected function determineInitialFilename(string $fileUrl): string
    {
        $filename = (string) ($this->file->filename ?? '');
        if ($filename === '') {
            $path = parse_url($fileUrl, PHP_URL_PATH) ?: null;
            $filename = $path ? basename($path) : '';
        }

        if ($filename === '') {
            $filename = 'file-'.$this->file->id;
        }

        return $filename;
    }

    protected function adjustFilenameForMime(string $filename, ?string $mime, string $currentPath): array
    {
        if (! $mime) {
            return [$filename, $currentPath];
        }

        $extension = $this->extensionFromMime($mime);
        if (! $extension) {
            return [$filename, $currentPath];
        }

        $currentExtension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if ($currentExtension === $extension) {
            return [$filename, $currentPath];
        }

        $base = $currentExtension !== ''
            ? Str::beforeLast($filename, '.'.$currentExtension)
            : $filename;

        $newFilename = $base.'.'.$extension;
        $newPath = 'downloads/'.$newFilename;

        return [$newFilename, $newPath];
    }

    protected function extensionFromMime(string $mime): ?string
    {
        $mime = strtolower(trim($mime));
        $extensions = MimeTypes::getDefault()->getExtensions($mime);

        return $extensions[0] ?? match ($mime) {
            'image/webp' => 'webp',
            default => null,
        };
    }

    protected function checkCivitaiVideoUrl(string $fileUrl): string
    {
        // Check if this is a CivitAI file that might be mislabeled
        if (strcasecmp((string) $this->file->source, 'CivitAI') !== 0) {
            return $fileUrl;
        }

        $thumbnailUrl = (string) ($this->file->thumbnail_url ?? '');
        $urlContainsMp4 = str_contains(strtolower($fileUrl), 'mp4');
        $thumbnailContainsMp4 = str_contains(strtolower($thumbnailUrl), 'mp4');

        // If URL or thumbnail suggests it's a video, check if server is returning webp
        if ($urlContainsMp4 || $thumbnailContainsMp4) {
            try {
                $head = Http::timeout(15)->head($fileUrl);
                if ($head->ok()) {
                    $contentType = strtolower((string) ($head->header('Content-Type') ?? ''));

                    return $this->checkCivitaiVideoUrlFromMime($fileUrl, $contentType);
                }
            } catch (\Throwable $e) {
                // If HEAD fails, continue with original URL
            }
        }

        return $fileUrl;
    }

    protected function checkCivitaiVideoUrlFromMime(string $fileUrl, string $contentType): string
    {
        // If server is returning image/webp but we expect a video, extract real video URL
        if (str_contains($contentType, 'image/webp')) {
            $extractor = new CivitaiVideoUrlExtractor;
            $videoUrl = $extractor->extractFromFileId($this->file->id);
            if ($videoUrl === '404_NOT_FOUND') {
                $fallbackUrl = $this->resolveVideoUrlFromMetadata();
                if ($fallbackUrl) {
                    $this->file->update([
                        'url' => $fallbackUrl,
                        'not_found' => false,
                    ]);

                    return $fallbackUrl;
                }

                return $fileUrl;
            }
            if (is_string($videoUrl) && filter_var($videoUrl, FILTER_VALIDATE_URL)) {
                // Update the file's URL to the real video URL
                $this->file->update([
                    'url' => $videoUrl,
                    'not_found' => false,
                ]);

                return $videoUrl;
            }

            $fallbackUrl = $this->resolveVideoUrlFromMetadata();
            if ($fallbackUrl) {
                $this->file->update([
                    'url' => $fallbackUrl,
                    'not_found' => false,
                ]);

                return $fallbackUrl;
            }
        }

        return $fileUrl;
    }

    protected function resolveVideoUrlFromMetadata(): ?string
    {
        $candidates = [
            (string) $this->file->thumbnail_url,
            (string) data_get($this->file->listing_metadata, 'url', ''),
            (string) data_get($this->file->detail_metadata, 'url', ''),
            (string) data_get($this->file->detail_metadata, 'downloadUrl', ''),
        ];

        $seen = [];

        foreach ($candidates as $candidate) {
            if ($candidate === '' || ! filter_var($candidate, FILTER_VALIDATE_URL)) {
                continue;
            }

            if (isset($seen[$candidate])) {
                continue;
            }

            $seen[$candidate] = true;

            try {
                $head = Http::timeout(20)->head($candidate);
            } catch (\Throwable $e) {
                continue;
            }

            if (! $head->ok()) {
                continue;
            }

            $contentType = strtolower((string) ($head->header('Content-Type') ?? ''));
            if ($contentType !== '' && str_starts_with($contentType, 'video/')) {
                return $candidate;
            }

            if ($head->status() === 405 || $contentType === '') {
                try {
                    $probe = Http::timeout(30)
                        ->withHeaders(['Range' => 'bytes=0-0'])
                        ->get($candidate);

                    if ($probe->status() === 206 || $probe->status() === 200) {
                        $probeType = strtolower((string) ($probe->header('Content-Type') ?? ''));
                        if ($probeType !== '' && str_starts_with($probeType, 'video/')) {
                            return $candidate;
                        }
                    }
                } catch (\Throwable $e) {
                    // ignore probe failures
                }
            }
        }

        return null;
    }

    protected function detectMimeFromFile(string $path): ?string
    {
        if (! is_file($path) || ! function_exists('finfo_open')) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        try {
            $mime = finfo_file($finfo, $path) ?: null;
        } finally {
            finfo_close($finfo);
        }

        return is_string($mime) ? strtolower(trim($mime)) : null;
    }
}
