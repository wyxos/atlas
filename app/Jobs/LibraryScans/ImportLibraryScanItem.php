<?php

namespace App\Jobs\LibraryScans;

use App\Enums\LibraryScanItemStatus;
use App\Enums\LibraryScanRunStatus;
use App\Models\LibraryScanItem;
use App\Services\LibraryScans\LibraryScanItemImporter;
use App\Services\LibraryScans\LibraryScanService;
use App\Support\AtlasStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ImportLibraryScanItem implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(public readonly int $itemId)
    {
        $this->onQueue('library-scans');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("library-scan-import-item-{$this->itemId}"))
                ->expireAfter($this->timeout + 300),
        ];
    }

    public function handle(
        AtlasStorage $storage,
        LibraryScanItemImporter $importer,
        LibraryScanService $scans,
    ): void {
        $item = LibraryScanItem::query()->with('run')->find($this->itemId);
        if (! $item || $item->isTerminal()) {
            return;
        }

        $run = $item->run;
        if (! $run) {
            $scans->markItemFailed($item, 'scan_run_missing', 'Library scan run is missing.');

            return;
        }

        if ($run->status === LibraryScanRunStatus::CANCELED) {
            $item->update([
                'status' => LibraryScanItemStatus::CANCELED,
                'phase' => 'canceled',
                'progress' => 100,
            ]);
            $scans->completeScanImportIfDone($run->fresh());

            return;
        }

        if ($run->status === LibraryScanRunStatus::PAUSED || in_array($run->status, LibraryScanRunStatus::terminal(), true)) {
            return;
        }

        $importer->import($item, $run, $storage, $scans);
        $scans->completeScanImportIfDone($run->fresh());
    }
}
