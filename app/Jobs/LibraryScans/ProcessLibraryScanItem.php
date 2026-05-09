<?php

namespace App\Jobs\LibraryScans;

use App\Enums\LibraryScanItemStatus;
use App\Enums\LibraryScanRunStatus;
use App\Models\LibraryScanItem;
use App\Services\LibraryScans\LibraryScanFileParser;
use App\Services\LibraryScans\LibraryScanService;
use App\Services\Local\LocalBrowseIndexSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessLibraryScanItem implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(private readonly int $itemId)
    {
        $this->onQueue('library-scans');
    }

    public function handle(LibraryScanFileParser $parser, LibraryScanService $scans): void
    {
        $item = LibraryScanItem::query()->with(['run', 'file'])->find($this->itemId);
        if (! $item || $item->isTerminal()) {
            return;
        }

        $run = $item->run;
        if (! $run || $run->status === LibraryScanRunStatus::CANCELED) {
            $item->update([
                'status' => LibraryScanItemStatus::CANCELED,
                'phase' => 'canceled',
                'progress' => 100,
            ]);
            $scans->broadcastItem($item->fresh());

            return;
        }

        if ($run->status === LibraryScanRunStatus::PAUSED) {
            $this->release(30);

            return;
        }

        if (! $item->file || ! $item->parser) {
            $item->update([
                'status' => LibraryScanItemStatus::COMPLETED,
                'phase' => 'completed',
                'progress' => 100,
            ]);
            $scans->broadcastItem($item->fresh());
            $scans->completeIfDone($run);

            return;
        }

        $item->update([
            'status' => LibraryScanItemStatus::PROCESSING,
            'phase' => 'processing',
            'progress' => 50,
        ]);
        $scans->broadcastItem($item->fresh());

        try {
            $parser->parse($item->file, $item->parser);
            app(LocalBrowseIndexSyncService::class)->syncFilesByIds([(int) $item->file_id]);

            $item->update([
                'status' => LibraryScanItemStatus::COMPLETED,
                'phase' => 'completed',
                'progress' => 100,
                'error_code' => null,
                'error_message' => null,
                'error_context' => null,
            ]);
            $scans->broadcastItem($item->fresh());
            $scans->completeIfDone($run);
        } catch (\Throwable $e) {
            $scans->markItemFailed($item, 'parser_failed', $e->getMessage(), [
                'exception' => $e::class,
            ]);
        }
    }
}
