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
            return;
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

    private function extractDomain(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host ? strtolower($host) : null;
    }
}
