<?php

namespace App\Services\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\Reaction;
use App\Services\MetricsService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

final class DownloadTransferRemovalService
{
    public function __construct(
        private readonly DownloadTransferExecutionLock $executionLock,
        private readonly DownloadTransferTempDirectory $tempDirectory,
        private readonly MetricsService $metricsService,
    ) {}

    public function bulkRemovalSyncLimit(): int
    {
        return max(1, (int) config('downloads.bulk_removal_sync_limit', 200));
    }

    public function bulkRemovalChunkSize(): int
    {
        return max(1, (int) config('downloads.bulk_removal_chunk_size', 100));
    }

    /**
     * @return list<int>
     */
    public function remove(DownloadTransfer $downloadTransfer, bool $alsoFromDisk = false): array
    {
        $downloadTransfer->loadMissing('file');

        $removedIds = [$downloadTransfer->id];
        $deletesFileRecord = $alsoFromDisk && $this->shouldDeleteFileRecord($downloadTransfer->file);

        if ($deletesFileRecord) {
            $removedIds = $this->transferIdsForFile($downloadTransfer->file);
        }

        $this->prepareForRemoval($downloadTransfer);

        if ($alsoFromDisk) {
            $this->deleteFileFromDisk($downloadTransfer);

            if ($deletesFileRecord) {
                return $removedIds === [] ? [$downloadTransfer->id] : $removedIds;
            }
        }

        $downloadTransfer->delete();

        return $removedIds;
    }

    /**
     * @param  list<int>  $ids
     * @param  callable(list<int>): void|null  $afterChunk
     */
    public function removeByIds(array $ids, bool $alsoFromDisk = false, ?callable $afterChunk = null): int
    {
        $removedCount = 0;
        $handledIds = [];

        foreach (array_chunk(array_values(array_unique($ids)), $this->bulkRemovalChunkSize()) as $chunkIds) {
            $transfers = DownloadTransfer::query()
                ->with('file')
                ->whereIn('id', $chunkIds)
                ->orderBy('id')
                ->get();

            if ($transfers->isEmpty()) {
                continue;
            }

            $chunkRemovedIds = [];
            foreach ($transfers as $transfer) {
                if (isset($handledIds[$transfer->id])) {
                    continue;
                }

                $removedIds = $this->remove($transfer, $alsoFromDisk);

                foreach ($removedIds as $removedId) {
                    $handledIds[$removedId] = true;
                }

                $chunkRemovedIds = [...$chunkRemovedIds, ...$removedIds];
            }

            $chunkRemovedIds = array_values(array_unique($chunkRemovedIds));
            $removedCount += count($chunkRemovedIds);

            if ($chunkRemovedIds !== [] && $afterChunk !== null) {
                $afterChunk($chunkRemovedIds);
            }
        }

        return $removedCount;
    }

    /**
     * @param  callable(list<int>): void|null  $afterChunk
     */
    public function removeCompleted(bool $alsoFromDisk = false, ?callable $afterChunk = null): int
    {
        $removedCount = 0;
        $handledIds = [];

        DownloadTransfer::query()
            ->with('file')
            ->where('status', DownloadTransferStatus::COMPLETED)
            ->orderBy('id')
            ->chunkById($this->bulkRemovalChunkSize(), function ($transfers) use ($alsoFromDisk, $afterChunk, &$removedCount, &$handledIds): void {
                $chunkRemovedIds = [];

                foreach ($transfers as $transfer) {
                    if (isset($handledIds[$transfer->id])) {
                        continue;
                    }

                    $removedIds = $this->remove($transfer, $alsoFromDisk);

                    foreach ($removedIds as $removedId) {
                        $handledIds[$removedId] = true;
                    }

                    $chunkRemovedIds = [...$chunkRemovedIds, ...$removedIds];
                }

                $chunkRemovedIds = array_values(array_unique($chunkRemovedIds));
                $removedCount += count($chunkRemovedIds);

                if ($chunkRemovedIds !== [] && $afterChunk !== null) {
                    $afterChunk($chunkRemovedIds);
                }
            });

        return $removedCount;
    }

    public function completedCount(): int
    {
        return DownloadTransfer::query()
            ->where('status', DownloadTransferStatus::COMPLETED)
            ->count();
    }

    public function cancelBatch(DownloadTransfer $downloadTransfer): void
    {
        if (! $downloadTransfer->batch_id) {
            return;
        }

        $batch = Bus::findBatch($downloadTransfer->batch_id);
        if ($batch) {
            $batch->cancel();
        }
    }

    public function prepareForRemoval(DownloadTransfer $downloadTransfer): void
    {
        if ($downloadTransfer->isTerminal() || $downloadTransfer->status === DownloadTransferStatus::CANCELED) {
            return;
        }

        $this->cancelBatch($downloadTransfer);
        $this->cleanupTransferParts($downloadTransfer);
        $this->resetFileProgress($downloadTransfer);
    }

    public function deleteFileFromDisk(DownloadTransfer $downloadTransfer): void
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

        $this->metricsService->applyDownloadClear($file, $wasDownloaded);

        if ($wasDownloaded) {
            $file->delete();

            return;
        }

        Reaction::query()->where('file_id', $file->id)->delete();

        $file->forceFill([
            'path' => null,
            'preview_path' => null,
            'poster_path' => null,
            'downloaded' => false,
            'downloaded_at' => null,
            'download_progress' => 0,
            'updated_at' => now(),
        ])->save();
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
        $tmpDir = $this->tempDirectory->forTransfer($downloadTransfer);

        if ($disk->exists($tmpDir)) {
            $disk->deleteDirectory($tmpDir);
        }
    }

    private function resetFileProgress(DownloadTransfer $downloadTransfer): void
    {
        File::query()->whereKey($downloadTransfer->file_id)->update([
            'download_progress' => 0,
            'updated_at' => now(),
        ]);
    }

    private function shouldDeleteFileRecord(?File $file): bool
    {
        return $file !== null && (bool) $file->downloaded;
    }

    /**
     * @return list<int>
     */
    private function transferIdsForFile(?File $file): array
    {
        if (! $file) {
            return [];
        }

        return DownloadTransfer::query()
            ->where('file_id', $file->id)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function shouldDeferYtDlpTempCleanup(DownloadTransfer $downloadTransfer): bool
    {
        return $this->isYtDlpTransfer($downloadTransfer)
            && $this->executionLock->isYtDlpActive($downloadTransfer->id);
    }

    private function isYtDlpTransfer(DownloadTransfer $downloadTransfer): bool
    {
        return data_get($downloadTransfer->file?->listing_metadata, 'download_via') === 'yt-dlp';
    }
}
