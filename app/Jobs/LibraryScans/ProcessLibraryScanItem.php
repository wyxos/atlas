<?php

namespace App\Jobs\LibraryScans;

use App\Enums\LibraryScanItemStatus;
use App\Enums\LibraryScanRunMode;
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

    public const string QUEUE = 'library-scan-parsers';

    public int $timeout = 300;

    public function __construct(
        public readonly int $itemId,
        public readonly bool $regeneratePreviewAssets = false,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(LibraryScanFileParser $parser, LibraryScanService $scans): void
    {
        $item = LibraryScanItem::query()->with(['run', 'file'])->find($this->itemId);
        if (! $item) {
            return;
        }

        $run = $item->run;
        $isBackgroundScanParser = $item->status === LibraryScanItemStatus::COMPLETED
            && $run?->mode === LibraryScanRunMode::SCAN
            && $item->file
            && $item->parser;

        if ($item->isTerminal() && ! $isBackgroundScanParser) {
            return;
        }

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
            if (! $item->isTerminal()) {
                $item->update([
                    'status' => LibraryScanItemStatus::COMPLETED,
                    'phase' => 'completed',
                    'progress' => 100,
                ]);
                $scans->broadcastItem($item->fresh());
                $scans->completeIfDone($run);
            }

            return;
        }

        if (! $isBackgroundScanParser) {
            $item->update([
                'status' => LibraryScanItemStatus::PROCESSING,
                'phase' => 'processing',
                'progress' => 50,
            ]);
            $scans->broadcastItem($item->fresh());
        }

        try {
            $result = $parser->parse($item->file, $item->parser, $this->regeneratePreviewAssets);
            $tasks = array_values(array_filter(
                is_array($result['tasks'] ?? null) ? $result['tasks'] : [],
                'is_string',
            ));

            $item = $item->fresh() ?? $item;
            if ($scans->queueMediaTasks($item, $tasks, $this->regeneratePreviewAssets) > 0) {
                return;
            }

            app(LocalBrowseIndexSyncService::class)->syncFilesByIds([(int) $item->file_id]);
            if ($isBackgroundScanParser) {
                $scans->broadcastItem($item->fresh(['mediaTasks']));
            } else {
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
            }
        } catch (\Throwable $e) {
            if ($isBackgroundScanParser) {
                $item->update([
                    'error_code' => 'parser_failed',
                    'error_message' => $e->getMessage(),
                    'error_context' => [
                        'exception' => $e::class,
                    ],
                ]);
                $scans->broadcastItem($item->fresh(['mediaTasks']));
            } else {
                $scans->markItemFailed($item, 'parser_failed', $e->getMessage(), [
                    'exception' => $e::class,
                ]);
            }
        }
    }
}
