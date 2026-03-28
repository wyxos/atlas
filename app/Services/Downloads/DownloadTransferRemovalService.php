<?php

namespace App\Services\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\DownloadedFileClearService;
use Illuminate\Support\Facades\Bus;

final class DownloadTransferRemovalService
{
    public function __construct(
        private readonly DownloadTransferExecutionLock $executionLock,
        private readonly DownloadTransferTempDirectory $tempDirectory,
        private readonly DownloadedFileClearService $downloadedFileClearService,
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
    public function remove(
        DownloadTransfer $downloadTransfer,
        bool $alsoFromDisk = false,
        bool $alsoDeleteRecord = false,
    ): array {
        $downloadTransfer->loadMissing('file');

        $removedIds = [$downloadTransfer->id];
        $deletesFileRecord = $alsoFromDisk && $alsoDeleteRecord && $downloadTransfer->file !== null;

        if ($deletesFileRecord) {
            $removedIds = $this->transferIdsForFile($downloadTransfer->file);
            $this->prepareTransfersForFileRemoval($downloadTransfer->file);
        } else {
            $this->prepareForRemoval($downloadTransfer);
        }

        if ($alsoFromDisk) {
            $this->clearFileFromDisk($downloadTransfer->file);

            if ($deletesFileRecord) {
                $downloadTransfer->file?->delete();

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
    public function removeByIds(
        array $ids,
        bool $alsoFromDisk = false,
        bool $alsoDeleteRecord = false,
        ?callable $afterChunk = null,
    ): int {
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

                $removedIds = $this->remove($transfer, $alsoFromDisk, $alsoDeleteRecord);

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
    public function removeCompleted(
        bool $alsoFromDisk = false,
        bool $alsoDeleteRecord = false,
        ?callable $afterChunk = null,
    ): int {
        $removedCount = 0;
        $handledIds = [];

        DownloadTransfer::query()
            ->with('file')
            ->where('status', DownloadTransferStatus::COMPLETED)
            ->orderBy('id')
            ->chunkById($this->bulkRemovalChunkSize(), function ($transfers) use ($alsoFromDisk, $alsoDeleteRecord, $afterChunk, &$removedCount, &$handledIds): void {
                $chunkRemovedIds = [];

                foreach ($transfers as $transfer) {
                    if (isset($handledIds[$transfer->id])) {
                        continue;
                    }

                    $removedIds = $this->remove($transfer, $alsoFromDisk, $alsoDeleteRecord);

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

    public function clearFileFromDisk(?File $file): void
    {
        if (! $file) {
            return;
        }

        $this->downloadedFileClearService->clear($file);
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

    private function prepareTransfersForFileRemoval(?File $file): void
    {
        if (! $file) {
            return;
        }

        DownloadTransfer::query()
            ->where('file_id', $file->id)
            ->orderBy('id')
            ->get()
            ->each(fn (DownloadTransfer $transfer) => $this->prepareForRemoval($transfer));
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
