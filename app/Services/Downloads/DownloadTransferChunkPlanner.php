<?php

namespace App\Services\Downloads;

use App\Enums\DownloadChunkStatus;
use App\Enums\DownloadTransferStatus;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class DownloadTransferChunkPlanner
{
    public function __construct(private readonly DownloadTransferTempDirectory $tempDirectory) {}

    /**
     * @return list<int>
     */
    public function plan(int $transferId, int $attempt, int $totalBytes): array
    {
        return DB::transaction(function () use ($transferId, $attempt, $totalBytes): array {
            $transfer = DownloadTransfer::query()->lockForUpdate()->find($transferId);
            if (! DownloadTransferGeneration::matches($transfer, $attempt, [DownloadTransferStatus::DOWNLOADING])) {
                return [];
            }

            $chunkCount = max(1, (int) config('downloads.chunk_count'));
            $chunkSize = (int) ceil($totalBytes / $chunkCount);
            $tmpDir = $this->tempDirectory->attempt($transferId, $attempt);
            $disk = Storage::disk(config('downloads.disk'));
            if (! $disk->exists($tmpDir)) {
                $disk->makeDirectory($tmpDir, 0755, true);
            }

            $chunkIds = [];
            for ($index = 0; $index < $chunkCount; $index++) {
                $start = $index * $chunkSize;
                if ($start >= $totalBytes) {
                    break;
                }

                $chunk = DownloadChunk::query()->create([
                    'download_transfer_id' => $transferId,
                    'index' => $index,
                    'range_start' => $start,
                    'range_end' => min($totalBytes - 1, (($index + 1) * $chunkSize) - 1),
                    'bytes_downloaded' => 0,
                    'status' => DownloadChunkStatus::PENDING,
                    'part_path' => "{$tmpDir}/part-{$index}.part",
                ]);
                $chunkIds[] = $chunk->id;
            }

            return $chunkIds;
        });
    }
}
