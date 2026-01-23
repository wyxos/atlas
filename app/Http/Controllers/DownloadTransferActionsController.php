<?php

namespace App\Http\Controllers;

use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadTransferPayload;
use App\Services\MetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

class DownloadTransferActionsController extends Controller
{
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

        return response()->json([
            'message' => 'Download paused.',
        ]);
    }

    public function resume(DownloadTransfer $downloadTransfer): JsonResponse
    {
        if ($downloadTransfer->status !== DownloadTransferStatus::PAUSED) {
            return response()->json([
                'message' => 'Download is not paused.',
            ], 409);
        }

        $this->cleanupTransferParts($downloadTransfer);
        $this->resetTransferProgress($downloadTransfer);

        $this->broadcastState($downloadTransfer);

        PumpDomainDownloads::dispatch($downloadTransfer->domain);

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

        PumpDomainDownloads::dispatch($downloadTransfer->domain);

        return response()->json([
            'message' => 'Download restarted.',
        ]);
    }

    public function destroy(DownloadTransfer $downloadTransfer): JsonResponse
    {
        $this->prepareForRemoval($downloadTransfer);
        $downloadTransfer->delete();

        return response()->json([
            'message' => 'Download removed.',
        ]);
    }

    public function destroyWithDisk(DownloadTransfer $downloadTransfer): JsonResponse
    {
        $this->prepareForRemoval($downloadTransfer);
        $this->deleteFileFromDisk($downloadTransfer);
        $downloadTransfer->delete();

        return response()->json([
            'message' => 'Download removed and file deleted from disk.',
        ]);
    }

    public function destroyBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:download_transfers,id',
        ]);

        $ids = $validated['ids'];
        $transfers = DownloadTransfer::query()->whereIn('id', $ids)->get();

        $removedIds = [];
        foreach ($transfers as $transfer) {
            $this->prepareForRemoval($transfer);
            $transfer->delete();
            $removedIds[] = $transfer->id;
        }

        return response()->json([
            'message' => 'Downloads removed.',
            'ids' => $removedIds,
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
            && $downloadTransfer->status !== DownloadTransferStatus::CANCELED;
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
        DownloadChunk::query()
            ->where('download_transfer_id', $downloadTransfer->id)
            ->delete();

        $disk = Storage::disk(config('downloads.disk'));
        $tmpDir = rtrim((string) config('downloads.tmp_dir'), '/').'/transfer-'.$downloadTransfer->id;

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

        $downloadTransfer->update($updates);

        $this->resetFileProgress($downloadTransfer);
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
}
