<?php

namespace App\Services\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\DownloadedFileClearService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

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
     * @param  array<int, mixed>  $fileIds
     * @return list<int>
     */
    public function cancelActiveForFileIds(array $fileIds): array
    {
        $fileIds = $this->normalizeFileIds($fileIds);
        if ($fileIds === []) {
            return [];
        }

        return DownloadTransfer::query()
            ->with('file')
            ->whereIn('file_id', $fileIds)
            ->whereIn('status', $this->cancelableActiveStatuses())
            ->orderBy('id')
            ->get()
            ->filter(fn (DownloadTransfer $transfer): bool => $this->cancel($transfer))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    public function cancel(DownloadTransfer $downloadTransfer, bool $dispatchPump = true): bool
    {
        if (! in_array($downloadTransfer->status, $this->cancelableActiveStatuses(), true)) {
            return false;
        }

        $this->cancelBatch($downloadTransfer);
        $this->cleanupTransferParts($downloadTransfer);

        $downloadTransfer->update([
            'status' => DownloadTransferStatus::CANCELED,
        ]);

        $this->resetFileProgress($downloadTransfer);
        $this->broadcastState($downloadTransfer);

        if ($dispatchPump && ! $this->shouldDeferYtDlpTempCleanup($downloadTransfer)) {
            PumpDomainDownloads::dispatch($downloadTransfer->domain);
        }

        return true;
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
        ?CarbonInterface $finishedBefore = null,
    ): int {
        $removedCount = 0;
        $handledIds = [];

        $this->completedQuery($finishedBefore)
            ->with('file')
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

    public function completedCount(?CarbonInterface $finishedBefore = null): int
    {
        return $this->completedQuery($finishedBefore)
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

        $tmpDir = $this->tempDirectory->forTransfer($downloadTransfer);

        try {
            $disk = Storage::disk(config('downloads.disk'));

            if ($disk->exists($tmpDir)) {
                $disk->deleteDirectory($tmpDir);
            }
        } catch (\Throwable) {
            // Temp cleanup failures should not block transfer removal.
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

    private function broadcastState(DownloadTransfer $downloadTransfer): void
    {
        $downloadTransfer->refresh();

        try {
            event(new DownloadTransferProgressUpdated(
                DownloadTransferPayload::forProgress($downloadTransfer, (int) ($downloadTransfer->last_broadcast_percent ?? 0))
            ));
        } catch (\Throwable) {
            // Broadcast errors shouldn't fail transfer cancellation.
        }
    }

    /**
     * @return list<string>
     */
    private function cancelableActiveStatuses(): array
    {
        return [
            DownloadTransferStatus::PENDING,
            DownloadTransferStatus::QUEUED,
            DownloadTransferStatus::PREPARING,
            DownloadTransferStatus::DOWNLOADING,
            DownloadTransferStatus::ASSEMBLING,
            DownloadTransferStatus::PAUSED,
        ];
    }

    /**
     * @param  array<int, mixed>  $fileIds
     * @return list<int>
     */
    private function normalizeFileIds(array $fileIds): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $fileId): int => is_numeric($fileId) ? (int) $fileId : 0, $fileIds),
            static fn (int $fileId): bool => $fileId > 0,
        )));
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

    /**
     * @return Builder<DownloadTransfer>
     */
    private function completedQuery(?CarbonInterface $finishedBefore = null): Builder
    {
        return DownloadTransfer::query()
            ->where('status', DownloadTransferStatus::COMPLETED)
            ->when($finishedBefore !== null, function (Builder $query) use ($finishedBefore): void {
                $query
                    ->whereNotNull('finished_at')
                    ->where('finished_at', '<=', $finishedBefore);
            });
    }
}
