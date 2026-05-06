<?php

namespace App\Services\LibraryScans;

use App\Enums\LibraryScanItemStatus;
use App\Enums\LibraryScanRunStatus;
use App\Events\LibraryScanItemUpdated;
use App\Events\LibraryScanRunUpdated;
use App\Jobs\LibraryScans\ProcessLibraryScanItem;
use App\Jobs\LibraryScans\ScanLibraryRun;
use App\Models\LibraryScanItem;
use App\Models\LibraryScanRun;

class LibraryScanService
{
    public function start(): LibraryScanRun
    {
        $activeRun = LibraryScanRun::query()
            ->whereIn('status', LibraryScanRunStatus::active())
            ->latest()
            ->first();

        if ($activeRun) {
            return $activeRun;
        }

        $run = LibraryScanRun::query()->create([
            'status' => LibraryScanRunStatus::PENDING,
            'phase' => 'pending',
        ]);

        $this->broadcastRun($run);
        ScanLibraryRun::dispatch($run->id);

        return $run;
    }

    public function pause(LibraryScanRun $run): LibraryScanRun
    {
        if (! in_array($run->status, [LibraryScanRunStatus::SCANNING, LibraryScanRunStatus::PROCESSING, LibraryScanRunStatus::PENDING], true)) {
            return $run;
        }

        $run->update([
            'status' => LibraryScanRunStatus::PAUSED,
            'phase' => $run->phase ?: 'paused',
            'paused_at' => now(),
        ]);

        $this->broadcastRun($run->fresh());

        return $run->fresh();
    }

    public function resume(LibraryScanRun $run): LibraryScanRun
    {
        if ($run->status !== LibraryScanRunStatus::PAUSED) {
            return $run;
        }

        $run->update([
            'status' => $run->scan_completed_at ? LibraryScanRunStatus::PROCESSING : LibraryScanRunStatus::SCANNING,
            'phase' => $run->scan_completed_at ? 'processing' : 'scanning',
            'paused_at' => null,
        ]);

        $run = $run->fresh();
        $this->broadcastRun($run);

        if ($run->scan_completed_at) {
            $this->dispatchPendingParsers($run);
            $this->completeIfDone($run);
        } else {
            ScanLibraryRun::dispatch($run->id);
        }

        return $run;
    }

    public function cancel(LibraryScanRun $run): LibraryScanRun
    {
        if (in_array($run->status, LibraryScanRunStatus::terminal(), true)) {
            return $run;
        }

        $run->items()
            ->whereNotIn('status', LibraryScanItemStatus::terminal())
            ->update([
                'status' => LibraryScanItemStatus::CANCELED,
                'phase' => 'canceled',
                'progress' => 100,
                'updated_at' => now(),
            ]);

        $run->update([
            'status' => LibraryScanRunStatus::CANCELED,
            'phase' => 'canceled',
            'canceled_at' => now(),
            'finished_at' => now(),
        ]);

        $this->refreshCounters($run->fresh());
        $this->broadcastRun($run->fresh());

        return $run->fresh();
    }

    public function restart(LibraryScanRun $run): LibraryScanRun
    {
        $this->cancel($run);

        return $this->start();
    }

    public function markItemFailed(LibraryScanItem $item, string $code, string $message, array $context = []): void
    {
        $item->update([
            'status' => LibraryScanItemStatus::FAILED,
            'phase' => 'failed',
            'progress' => 100,
            'error_code' => $code,
            'error_message' => $message,
            'error_context' => $context,
        ]);

        $this->broadcastItem($item->fresh());
        $this->completeIfDone($item->run()->first());
    }

    public function completeIfDone(?LibraryScanRun $run): void
    {
        if (! $run || in_array($run->status, [LibraryScanRunStatus::PAUSED, LibraryScanRunStatus::CANCELED], true)) {
            return;
        }

        $this->refreshCounters($run);

        if (! $run->scan_completed_at) {
            return;
        }

        $remaining = $run->items()
            ->whereNotIn('status', LibraryScanItemStatus::terminal())
            ->exists();

        if ($remaining) {
            return;
        }

        $run->update([
            'status' => $run->files_failed > 0 ? LibraryScanRunStatus::FAILED : LibraryScanRunStatus::COMPLETED,
            'phase' => $run->files_failed > 0 ? 'failed' : 'completed',
            'finished_at' => now(),
        ]);

        $this->broadcastRun($run->fresh());
    }

    public function dispatchPendingParsers(LibraryScanRun $run): void
    {
        $run->items()
            ->where('status', LibraryScanItemStatus::IMPORTED)
            ->whereNotNull('parser')
            ->each(fn (LibraryScanItem $item) => ProcessLibraryScanItem::dispatch($item->id));
    }

    public function refreshCounters(LibraryScanRun $run): LibraryScanRun
    {
        $run->update([
            'files_found' => $run->items()->count(),
            'files_imported' => $run->items()->whereNotNull('imported_path')->count(),
            'files_duplicate' => $run->items()->where('duplicate', true)->count(),
            'files_processed' => $run->items()->where('status', LibraryScanItemStatus::COMPLETED)->count(),
            'files_failed' => $run->items()->where('status', LibraryScanItemStatus::FAILED)->count(),
            'files_canceled' => $run->items()->where('status', LibraryScanItemStatus::CANCELED)->count(),
        ]);

        return $run->fresh();
    }

    public function broadcastRun(LibraryScanRun $run): void
    {
        try {
            event(new LibraryScanRunUpdated(LibraryScanPayload::run($run)));
        } catch (\Throwable) {
            // Broadcasting must not affect scan work.
        }
    }

    public function broadcastItem(LibraryScanItem $item): void
    {
        try {
            event(new LibraryScanItemUpdated(LibraryScanPayload::item($item)));
        } catch (\Throwable) {
            // Broadcasting must not affect scan work.
        }
    }
}
