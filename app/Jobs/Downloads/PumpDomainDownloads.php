<?php

namespace App\Jobs\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferQueued;
use App\Models\DownloadTransfer;
use App\Services\Downloads\DownloadTransferPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class PumpDomainDownloads implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $domain) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $maxPerDomain = (int) config('downloads.max_transfers_per_domain');
        $maxTotal = (int) config('downloads.max_transfers_total');

        $transferIds = DB::transaction(function () use ($maxPerDomain, $maxTotal) {
            DownloadTransfer::query()
                ->where('domain', $this->domain)
                ->lockForUpdate()
                ->count();

            $activeStatuses = [
                DownloadTransferStatus::QUEUED,
                DownloadTransferStatus::PREPARING,
                DownloadTransferStatus::DOWNLOADING,
                DownloadTransferStatus::ASSEMBLING,
            ];

            DownloadTransfer::query()
                ->whereIn('status', $activeStatuses)
                ->lockForUpdate()
                ->count();

            $activeCount = DownloadTransfer::query()
                ->where('domain', $this->domain)
                ->whereIn('status', $activeStatuses)
                ->count();

            $globalActiveCount = DownloadTransfer::query()
                ->whereIn('status', $activeStatuses)
                ->count();

            $available = min(
                max(0, $maxPerDomain - $activeCount),
                max(0, $maxTotal - $globalActiveCount),
            );
            if ($available === 0) {
                return [];
            }

            $ids = DownloadTransfer::query()
                ->where('domain', $this->domain)
                ->where('status', DownloadTransferStatus::PENDING)
                ->orderBy('created_at')
                ->limit($available)
                ->pluck('id')
                ->all();

            if (empty($ids)) {
                return [];
            }

            DownloadTransfer::query()
                ->whereIn('id', $ids)
                ->where('status', DownloadTransferStatus::PENDING)
                ->update([
                    'status' => DownloadTransferStatus::QUEUED,
                    'queued_at' => now(),
                    'updated_at' => now(),
                ]);

            return $ids;
        });

        $transfers = DownloadTransfer::query()
            ->with(['file:id,filename,path,url,thumbnail_url,size,referrer_url'])
            ->whereIn('id', $transferIds)
            ->get()
            ->keyBy('id');

        foreach ($transferIds as $transferId) {
            QueueDownloadTransfer::dispatch($transferId);
            try {
                $transfer = $transfers->get($transferId);
                if ($transfer) {
                    event(new DownloadTransferQueued(DownloadTransferPayload::forQueued($transfer)));
                }
            } catch (\Throwable) {
                // Ignore broadcast failures for queueing updates.
            }
        }
    }
}
