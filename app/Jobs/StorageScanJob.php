<?php

namespace App\Jobs;

use App\Events\StorageScanProgress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StorageScanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $userId,
        public string $disk = 'atlas'
    ) {}

    public function handle(): void
    {
        try {
            $all = Storage::disk($this->disk)->allFiles();
            // Exclude .app subtree (app-generated assets)
            $files = array_values(array_filter($all, function ($p) {
                $p = ltrim(str_replace('\\', '/', (string) $p), '/');

                return $p !== '' && ! Str::startsWith($p, '.app/') && ! Str::contains($p, '/.app/');
            }));
            $total = count($files);
            $enqueued = 0;

            // Initial broadcast (scan phase)
            event(new StorageScanProgress($this->userId, $total, $enqueued, false, false, 'Starting scan'));

            // Reset processing counters so downstream jobs have a fresh slate
            Cache::put('storage_processing:'.$this->userId.':total', 0, now()->addMinutes(60));
            Cache::put('storage_processing:'.$this->userId.':done', 0, now()->addMinutes(60));
            Cache::put('storage_processing:'.$this->userId.':failed', 0, now()->addMinutes(60));
            event(new \App\Events\StorageProcessingProgress($this->userId, 0, 0, 0));

            // Build processing jobs
            foreach ($files as $path) {
                // Cancellation check
                if (Cache::get($this->cancelKey(), false)) {
                    event(new StorageScanProgress($this->userId, $total, $enqueued, true, true, 'Scan canceled'));
                    Cache::forget($this->statusKey());

                    return;
                }

                ClassifyMediaJob::dispatch($this->userId, $this->disk, (string) $path)
                    ->onQueue('processing');

                $enqueued++;

                // Update cache status (processed = enqueued during scan phase)
                Cache::put($this->statusKey(), [
                    'total' => $total,
                    'processed' => $enqueued,
                    'running' => true,
                ], now()->addMinutes(10));

                // Broadcast scan progress
                event(new StorageScanProgress($this->userId, $total, $enqueued, false, false, null));
            }

            // Mark scan complete and provide processing batch info for UI
            Cache::put($this->statusKey(), [
                'total' => $total,
                'processed' => $enqueued,
                'running' => false,
                'processing_batch_id' => null,
                'processing_total' => (int) Cache::get('storage_processing:'.$this->userId.':total', 0),
            ], now()->addMinutes(30));

            event(new StorageScanProgress($this->userId, $total, $enqueued, true, false, 'Scan complete'));
        } catch (\Throwable $e) {
            // Reset UI state and broadcast failure so controls re-enable
            Cache::put($this->statusKey(), [
                'total' => 0,
                'processed' => 0,
                'running' => false,
            ], now()->addMinutes(10));
            event(new StorageScanProgress($this->userId, 0, 0, true, false, 'Scan failed'));

            // Don't rethrow; treat as handled so the job won't be retried and UI isn't stuck
            report($e);

            return;
        }
    }

    protected function statusKey(): string
    {
        return 'storage_scan:'.$this->userId.':status';
    }

    protected function cancelKey(): string
    {
        return 'storage_scan:'.$this->userId.':cancel';
    }

    public function failed(\Throwable $e): void
    {
        // Ensure UI is re-enabled even if exception bubbles up
        Cache::put($this->statusKey(), [
            'total' => 0,
            'processed' => 0,
            'running' => false,
        ], now()->addMinutes(10));
        event(new StorageScanProgress($this->userId, 0, 0, true, false, 'Scan failed'));
    }
}
