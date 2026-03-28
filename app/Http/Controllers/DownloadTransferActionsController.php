<?php

namespace App\Http\Controllers;

use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Events\DownloadTransfersRemoved;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Jobs\Downloads\RemoveDownloadTransfers;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadTransferActionAvailability;
use App\Services\Downloads\DownloadTransferExecutionLock;
use App\Services\Downloads\DownloadTransferPayload;
use App\Services\Downloads\DownloadTransferRemovalService;
use App\Services\Downloads\DownloadTransferTempDirectory;
use App\Services\MetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

class DownloadTransferActionsController extends Controller
{
    public function __construct(private readonly DownloadTransferRemovalService $transferRemovalService) {}

    public function pause(DownloadTransfer $downloadTransfer): JsonResponse
    {
        if (! $this->canPause($downloadTransfer)) {
            return response()->json([
                'message' => 'Download is not active.',
            ], 409);
        }

        $this->cancelBatch($downloadTransfer);

        $downloadTransfer->update([
            'status' => DownloadTransferStatus::PAUSED,
        ]);

        $this->broadcastState($downloadTransfer);
        $this->dispatchPumpIfReady($downloadTransfer);

        return response()->json([
            'message' => 'Download paused.',
        ]);
    }

    public function resume(DownloadTransfer $downloadTransfer): JsonResponse
    {
        $downloadTransfer->loadMissing('file');

        if (! DownloadTransferActionAvailability::canResume($downloadTransfer)) {
            return response()->json([
                'message' => $downloadTransfer->status === DownloadTransferStatus::FAILED
                    ? 'Download cannot resume. Use Restart to fetch it from scratch.'
                    : 'Download is not resumable.',
            ], 409);
        }

        if ($downloadTransfer->status === DownloadTransferStatus::PAUSED) {
            $this->cleanupTransferParts($downloadTransfer);
            $this->resetTransferProgress($downloadTransfer);
        } else {
            $this->queueTransferForResume($downloadTransfer);
        }

        $this->broadcastState($downloadTransfer);

        $this->dispatchPumpIfReady($downloadTransfer);

        return response()->json([
            'message' => 'Download resumed.',
        ]);
    }

    public function cancel(DownloadTransfer $downloadTransfer): JsonResponse
    {
        if (! $this->canCancel($downloadTransfer)) {
            return response()->json([
                'message' => 'Download is already finished.',
            ], 409);
        }

        $this->cancelBatch($downloadTransfer);
        $this->cleanupTransferParts($downloadTransfer);

        $downloadTransfer->update([
            'status' => DownloadTransferStatus::CANCELED,
        ]);

        $this->resetFileProgress($downloadTransfer);
        $this->broadcastState($downloadTransfer);
        $this->dispatchPumpIfReady($downloadTransfer);

        return response()->json([
            'message' => 'Download canceled.',
        ]);
    }

    public function pauseBatchTransfers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:download_transfers,id',
        ]);

        $transfers = DownloadTransfer::query()
            ->whereIn('id', $validated['ids'])
            ->get();

        $pausedIds = [];
        $skippedIds = [];

        foreach ($transfers as $transfer) {
            if (! $this->canPause($transfer)) {
                $skippedIds[] = $transfer->id;

                continue;
            }

            $this->cancelBatch($transfer);
            $transfer->update([
                'status' => DownloadTransferStatus::PAUSED,
            ]);
            $this->broadcastState($transfer);
            $this->dispatchPumpIfReady($transfer);
            $pausedIds[] = $transfer->id;
        }

        return response()->json([
            'message' => 'Downloads paused.',
            'ids' => $pausedIds,
            'skipped_ids' => $skippedIds,
        ]);
    }

    public function cancelBatchTransfers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:download_transfers,id',
        ]);

        $transfers = DownloadTransfer::query()
            ->whereIn('id', $validated['ids'])
            ->get();

        $canceledIds = [];
        $skippedIds = [];

        foreach ($transfers as $transfer) {
            if (! $this->canCancel($transfer)) {
                $skippedIds[] = $transfer->id;

                continue;
            }

            $this->cancelBatch($transfer);
            $this->cleanupTransferParts($transfer);
            $transfer->update([
                'status' => DownloadTransferStatus::CANCELED,
            ]);
            $this->resetFileProgress($transfer);
            $this->broadcastState($transfer);
            $this->dispatchPumpIfReady($transfer);
            $canceledIds[] = $transfer->id;
        }

        return response()->json([
            'message' => 'Downloads canceled.',
            'ids' => $canceledIds,
            'skipped_ids' => $skippedIds,
        ]);
    }

    public function restart(DownloadTransfer $downloadTransfer): JsonResponse
    {
        if (! in_array($downloadTransfer->status, [
            DownloadTransferStatus::FAILED,
            DownloadTransferStatus::CANCELED,
            DownloadTransferStatus::COMPLETED,
        ], true)) {
            return response()->json([
                'message' => 'Download is not restartable.',
            ], 409);
        }

        $this->cancelBatch($downloadTransfer);
        $this->cleanupTransferParts($downloadTransfer);
        $this->resetTransferProgress($downloadTransfer);

        $this->broadcastState($downloadTransfer);

        $this->dispatchPumpIfReady($downloadTransfer);

        return response()->json([
            'message' => 'Download restarted.',
        ]);
    }

    public function destroy(DownloadTransfer $downloadTransfer): JsonResponse
    {
        $removedIds = $this->transferRemovalService->remove($downloadTransfer);
        $this->broadcastRemoved($removedIds);

        return response()->json([
            'message' => 'Download removed.',
            'ids' => $removedIds,
            'count' => count($removedIds),
            'queued' => false,
        ]);
    }

    public function destroyWithDisk(DownloadTransfer $downloadTransfer): JsonResponse
    {
        $validated = request()->validate([
            'also_delete_record' => 'sometimes|boolean',
        ]);

        $alsoDeleteRecord = (bool) ($validated['also_delete_record'] ?? false);
        $removedIds = $this->transferRemovalService->remove($downloadTransfer, true, $alsoDeleteRecord);
        $this->broadcastRemoved($removedIds);

        return response()->json([
            'message' => $alsoDeleteRecord
                ? 'Download removed, file deleted from disk, and Atlas record deleted.'
                : 'Download removed and file deleted from disk.',
            'ids' => $removedIds,
            'count' => count($removedIds),
            'queued' => false,
        ]);
    }

    public function destroyBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:download_transfers,id',
            'also_from_disk' => 'sometimes|boolean',
            'also_delete_record' => 'sometimes|boolean',
        ]);

        $ids = $validated['ids'];
        $alsoFromDisk = (bool) ($validated['also_from_disk'] ?? false);
        $alsoDeleteRecord = $alsoFromDisk && (bool) ($validated['also_delete_record'] ?? false);

        if ($this->shouldQueueBulkRemoval(count($ids))) {
            RemoveDownloadTransfers::dispatch(
                ids: $ids,
                alsoFromDisk: $alsoFromDisk,
                alsoDeleteRecord: $alsoDeleteRecord,
            );

            return response()->json([
                'message' => 'Download removal queued.',
                'count' => count($ids),
                'queued' => true,
            ]);
        }

        $removedIds = [];
        $this->transferRemovalService->removeByIds($ids, $alsoFromDisk, $alsoDeleteRecord, function (array $chunkIds) use (&$removedIds): void {
            $removedIds = [...$removedIds, ...$chunkIds];
        });
        $this->broadcastRemoved($removedIds);

        return response()->json([
            'message' => 'Downloads removed.',
            'ids' => $removedIds,
            'count' => count($removedIds),
            'queued' => false,
        ]);
    }

    public function destroyCompleted(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'also_from_disk' => 'sometimes|boolean',
            'also_delete_record' => 'sometimes|boolean',
        ]);

        $alsoFromDisk = (bool) ($validated['also_from_disk'] ?? false);
        $alsoDeleteRecord = $alsoFromDisk && (bool) ($validated['also_delete_record'] ?? false);
        $completedCount = $this->transferRemovalService->completedCount();

        if ($completedCount === 0) {
            return response()->json([
                'message' => 'Completed downloads removed.',
                'count' => 0,
                'ids' => [],
                'queued' => false,
            ]);
        }

        if ($this->shouldQueueBulkRemoval($completedCount)) {
            RemoveDownloadTransfers::dispatch(
                alsoFromDisk: $alsoFromDisk,
                alsoDeleteRecord: $alsoDeleteRecord,
                completedOnly: true,
            );

            return response()->json([
                'message' => 'Completed download removal queued.',
                'count' => $completedCount,
                'queued' => true,
            ]);
        }

        $removedIds = [];
        $removedCount = $this->transferRemovalService->removeCompleted($alsoFromDisk, $alsoDeleteRecord, function (array $chunkIds) use (&$removedIds): void {
            $removedIds = [...$removedIds, ...$chunkIds];
        });
        $this->broadcastRemoved($removedIds);

        return response()->json([
            'message' => 'Completed downloads removed.',
            'count' => $removedCount,
            'ids' => $removedIds,
            'queued' => false,
        ]);
    }

    private function canPause(DownloadTransfer $downloadTransfer): bool
    {
        return in_array($downloadTransfer->status, [
            DownloadTransferStatus::PENDING,
            DownloadTransferStatus::QUEUED,
            DownloadTransferStatus::PREPARING,
            DownloadTransferStatus::DOWNLOADING,
            DownloadTransferStatus::ASSEMBLING,
        ], true);
    }

    private function canCancel(DownloadTransfer $downloadTransfer): bool
    {
        return ! $downloadTransfer->isTerminal()
            && $downloadTransfer->status !== DownloadTransferStatus::CANCELED
            && $downloadTransfer->status !== DownloadTransferStatus::PREVIEWING;
    }

    private function cancelBatch(DownloadTransfer $downloadTransfer): void
    {
        if (! $downloadTransfer->batch_id) {
            return;
        }

        $batch = Bus::findBatch($downloadTransfer->batch_id);
        if ($batch) {
            $batch->cancel();
        }
    }

    private function prepareForRemoval(DownloadTransfer $downloadTransfer): void
    {
        if ($downloadTransfer->isTerminal() || $downloadTransfer->status === DownloadTransferStatus::CANCELED) {
            return;
        }

        $this->cancelBatch($downloadTransfer);
        $this->cleanupTransferParts($downloadTransfer);
        $this->resetFileProgress($downloadTransfer);
    }

    private function cleanupTransferParts(DownloadTransfer $downloadTransfer): void
    {
        $downloadTransfer->loadMissing('file');

        DownloadChunk::query()
            ->where('download_transfer_id', $downloadTransfer->id)
            ->delete();

        if ($this->shouldDeferYtDlpTempCleanup($downloadTransfer)) {
            return;
        }

        $disk = Storage::disk(config('downloads.disk'));
        $tmpDir = app(DownloadTransferTempDirectory::class)->forTransfer($downloadTransfer);

        if ($disk->exists($tmpDir)) {
            $disk->deleteDirectory($tmpDir);
        }
    }

    private function resetTransferProgress(DownloadTransfer $downloadTransfer): void
    {
        $downloadTransfer->loadMissing('file');

        $updates = [
            'status' => DownloadTransferStatus::PENDING,
            'bytes_total' => null,
            'bytes_downloaded' => 0,
            'last_broadcast_percent' => 0,
            'queued_at' => null,
            'started_at' => null,
            'finished_at' => null,
            'failed_at' => null,
            'batch_id' => null,
            'error' => null,
        ];

        if ($downloadTransfer->file?->url) {
            $freshUrl = (string) $downloadTransfer->file->url;
            $updates['url'] = $freshUrl;

            $host = parse_url($freshUrl, PHP_URL_HOST);
            if ($host) {
                $updates['domain'] = strtolower($host);
            }
        }

        if ($this->isYtDlpTransfer($downloadTransfer)) {
            $updates['attempt'] = ((int) ($downloadTransfer->attempt ?? 0)) + 1;
        }

        $downloadTransfer->update($updates);

        $this->resetFileProgress($downloadTransfer);
    }

    private function queueTransferForResume(DownloadTransfer $downloadTransfer): void
    {
        $downloadTransfer->update([
            'status' => DownloadTransferStatus::PENDING,
            'queued_at' => null,
            'started_at' => null,
            'finished_at' => null,
            'failed_at' => null,
            'batch_id' => null,
            'error' => null,
        ]);
    }

    private function resetFileProgress(DownloadTransfer $downloadTransfer): void
    {
        File::query()->whereKey($downloadTransfer->file_id)->update([
            'download_progress' => 0,
            'updated_at' => now(),
        ]);
    }

    private function deleteFileFromDisk(DownloadTransfer $downloadTransfer): void
    {
        $downloadTransfer->loadMissing('file');

        if (! $downloadTransfer->file) {
            return;
        }

        $file = $downloadTransfer->file;
        $wasDownloaded = (bool) $file->downloaded;
        $disk = Storage::disk(config('downloads.disk'));

        if ($file->path && $disk->exists($file->path)) {
            $disk->delete($file->path);
        }

        if ($file->preview_path && $disk->exists($file->preview_path)) {
            $disk->delete($file->preview_path);
        }
        if ($file->poster_path && $disk->exists($file->poster_path)) {
            $disk->delete($file->poster_path);
        }

        File::query()->whereKey($file->id)->update([
            'path' => null,
            'preview_path' => null,
            'poster_path' => null,
            'downloaded' => false,
            'downloaded_at' => null,
            'download_progress' => 0,
            'updated_at' => now(),
        ]);

        app(MetricsService::class)->applyDownloadClear($file, $wasDownloaded);
    }

    private function broadcastState(DownloadTransfer $downloadTransfer): void
    {
        $downloadTransfer->refresh();

        try {
            event(new DownloadTransferProgressUpdated(
                DownloadTransferPayload::forProgress($downloadTransfer, (int) ($downloadTransfer->last_broadcast_percent ?? 0))
            ));
        } catch (\Throwable) {
            // Broadcast errors shouldn't fail download actions.
        }
    }

    private function dispatchPumpIfReady(DownloadTransfer $downloadTransfer): void
    {
        $downloadTransfer->loadMissing('file');

        if ($this->shouldDeferYtDlpTempCleanup($downloadTransfer)) {
            return;
        }

        PumpDomainDownloads::dispatch($downloadTransfer->domain);
    }

    private function shouldDeferYtDlpTempCleanup(DownloadTransfer $downloadTransfer): bool
    {
        return $this->isYtDlpTransfer($downloadTransfer)
            && app(DownloadTransferExecutionLock::class)->isYtDlpActive($downloadTransfer->id);
    }

    private function isYtDlpTransfer(DownloadTransfer $downloadTransfer): bool
    {
        return data_get($downloadTransfer->file?->listing_metadata, 'download_via') === 'yt-dlp';
    }

    private function shouldQueueBulkRemoval(int $count): bool
    {
        return $count > $this->transferRemovalService->bulkRemovalSyncLimit();
    }

    /**
     * @param  list<int>  $ids
     */
    private function broadcastRemoved(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        try {
            event(new DownloadTransfersRemoved($ids));
        } catch (\Throwable) {
            // Broadcast errors shouldn't fail removal actions.
        }
    }
}
