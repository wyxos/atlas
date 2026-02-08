<?php

namespace App\Jobs;

use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferCreated;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadTransferPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class DownloadFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $fileId)
    {
        $this->onQueue('downloads');
    }

    public function handle(): void
    {
        $file = File::find($this->fileId);

        if (! $file || ! $file->url) {
            return;
        }

        if ($file->downloaded && ! empty($file->path)) {
            if ($this->isInvalidDownloadedFile($file)) {
                $this->repairDownloadedFile($file);
            } else {
                return;
            }
        }

        $domain = $this->extractDomain((string) $file->url) ?? 'unknown';

        $activeStatuses = [
            DownloadTransferStatus::PENDING,
            DownloadTransferStatus::QUEUED,
            DownloadTransferStatus::PREPARING,
            DownloadTransferStatus::DOWNLOADING,
            DownloadTransferStatus::ASSEMBLING,
            DownloadTransferStatus::PAUSED,
        ];

        $existing = DownloadTransfer::query()
            ->where('file_id', $file->id)
            ->whereIn('status', $activeStatuses)
            ->latest('id')
            ->first();

        if (! $existing) {
            $transfer = DownloadTransfer::query()->create([
                'file_id' => $file->id,
                'url' => (string) $file->url,
                'domain' => $domain,
                'status' => DownloadTransferStatus::PENDING,
                'bytes_total' => null,
                'bytes_downloaded' => 0,
                'last_broadcast_percent' => 0,
            ]);

            $transfer->setRelation('file', $file);
            try {
                event(new DownloadTransferCreated(DownloadTransferPayload::forQueued($transfer)));
            } catch (\Throwable) {
                // Broadcast errors shouldn't fail downloads.
            }
        }

        PumpDomainDownloads::dispatch($domain);
    }

    private function isInvalidDownloadedFile(File $file): bool
    {
        if (! $file->path) {
            return true;
        }

        $disk = Storage::disk(config('downloads.disk'));
        if (! $disk->exists($file->path)) {
            return true;
        }

        $tagName = data_get($file->listing_metadata, 'tag_name');
        if (! in_array($tagName, ['video', 'iframe'], true)) {
            return false;
        }

        $path = strtolower((string) $file->path);
        if (str_ends_with($path, '.html') || str_ends_with($path, '.htm')) {
            return true;
        }

        $mime = strtolower((string) ($file->mime_type ?? ''));
        if ($mime !== '' && str_starts_with($mime, 'text/html')) {
            return true;
        }

        return false;
    }

    private function repairDownloadedFile(File $file): void
    {
        $disk = Storage::disk(config('downloads.disk'));

        $path = $file->path;
        if (is_string($path) && $path !== '' && $disk->exists($path)) {
            try {
                $disk->delete($path);
            } catch (\Throwable) {
                // If cleanup fails we can still retry the download.
            }
        }

        $pageUrl = data_get($file->listing_metadata, 'page_url');
        if (! is_string($pageUrl) || $pageUrl === '') {
            $pageUrl = null;
        }

        $file->forceFill([
            'downloaded' => false,
            'downloaded_at' => null,
            'download_progress' => 0,
            'path' => null,
            'preview_path' => null,
            'poster_path' => null,
            'size' => null,
            'mime_type' => null,
            'ext' => null,
            // Prefer the actual page URL for video/iframe downloads (yt-dlp fallback uses it).
            'url' => $pageUrl ?? $file->url,
            'referrer_url' => $pageUrl ?? $file->referrer_url,
        ])->save();
    }

    private function extractDomain(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host ? strtolower($host) : null;
    }
}
