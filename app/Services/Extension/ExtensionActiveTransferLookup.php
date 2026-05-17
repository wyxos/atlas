<?php

namespace App\Services\Extension;

use App\Enums\DownloadTransferStatus;
use App\Models\DownloadTransfer;

class ExtensionActiveTransferLookup
{
    public function forFileId(int $fileId): ?DownloadTransfer
    {
        return $this->byFileId([$fileId])[$fileId] ?? null;
    }

    /**
     * @param  array<int, mixed>  $fileIds
     * @return array<int, DownloadTransfer>
     */
    public function byFileId(array $fileIds): array
    {
        $fileIds = $this->normalizeFileIds($fileIds);
        if ($fileIds === []) {
            return [];
        }

        return DownloadTransfer::query()
            ->select(['id', 'file_id', 'status', 'last_broadcast_percent'])
            ->whereIn('file_id', $fileIds)
            ->whereIn('status', $this->activeTransferStatuses())
            ->orderByDesc('id')
            ->get()
            ->unique(fn (DownloadTransfer $transfer): int => (int) $transfer->file_id)
            ->keyBy(fn (DownloadTransfer $transfer): int => (int) $transfer->file_id)
            ->all();
    }

    /**
     * @return list<string>
     */
    private function activeTransferStatuses(): array
    {
        return [
            DownloadTransferStatus::PENDING,
            DownloadTransferStatus::QUEUED,
            DownloadTransferStatus::PREPARING,
            DownloadTransferStatus::DOWNLOADING,
            DownloadTransferStatus::ASSEMBLING,
            DownloadTransferStatus::PREVIEWING,
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
}
