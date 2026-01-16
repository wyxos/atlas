<?php

namespace App\Http\Controllers;

use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use Illuminate\Http\JsonResponse;
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

        PumpDomainDownloads::dispatch($downloadTransfer->domain);

        return response()->json([
            'message' => 'Download resumed.',
        ]);
    }

    public function cancel(DownloadTransfer $downloadTransfer): JsonResponse
    {
        if ($downloadTransfer->isTerminal() || $downloadTransfer->status === DownloadTransferStatus::CANCELED) {
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

        return response()->json([
            'message' => 'Download canceled.',
        ]);
    }

    public function restart(DownloadTransfer $downloadTransfer): JsonResponse
    {
        if (! in_array($downloadTransfer->status, [
            DownloadTransferStatus::FAILED,
            DownloadTransferStatus::CANCELED,
        ], true)) {
            return response()->json([
                'message' => 'Download is not restartable.',
            ], 409);
        }

        $this->cancelBatch($downloadTransfer);
        $this->cleanupTransferParts($downloadTransfer);
        $this->resetTransferProgress($downloadTransfer);

        PumpDomainDownloads::dispatch($downloadTransfer->domain);

        return response()->json([
            'message' => 'Download restarted.',
        ]);
    }

    public function destroy(DownloadTransfer $downloadTransfer): JsonResponse
    {
        if (! $downloadTransfer->isTerminal() && $downloadTransfer->status !== DownloadTransferStatus::CANCELED) {
            $this->cancelBatch($downloadTransfer);
            $this->cleanupTransferParts($downloadTransfer);
            $this->resetFileProgress($downloadTransfer);
        }

        $downloadTransfer->delete();

        return response()->json([
            'message' => 'Download removed.',
        ]);
    }

    public function destroyWithDisk(DownloadTransfer $downloadTransfer): JsonResponse
    {
        if (! $downloadTransfer->isTerminal() && $downloadTransfer->status !== DownloadTransferStatus::CANCELED) {
            $this->cancelBatch($downloadTransfer);
            $this->cleanupTransferParts($downloadTransfer);
            $this->resetFileProgress($downloadTransfer);
        }

        $this->deleteFileFromDisk($downloadTransfer);
        $downloadTransfer->delete();

        return response()->json([
            'message' => 'Download removed and file deleted from disk.',
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
        $downloadTransfer->update([
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
        ]);

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
        $disk = Storage::disk(config('downloads.disk'));

        if ($file->path && $disk->exists($file->path)) {
            $disk->delete($file->path);
        }

        if ($file->thumbnail_path && $disk->exists($file->thumbnail_path)) {
            $disk->delete($file->thumbnail_path);
        }

        File::query()->whereKey($file->id)->update([
            'path' => null,
            'thumbnail_path' => null,
            'downloaded' => false,
            'downloaded_at' => null,
            'download_progress' => 0,
            'updated_at' => now(),
        ]);
    }
}
