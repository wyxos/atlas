<?php

namespace App\Services\Downloads;

use App\Models\DownloadTransfer;
use Closure;
use Illuminate\Support\Facades\DB;

final class DownloadTransferGeneration
{
    /**
     * @param  list<string>  $statuses
     */
    public static function matches(?DownloadTransfer $transfer, int $attempt, array $statuses): bool
    {
        return $transfer !== null
            && (int) ($transfer->attempt ?? 0) === $attempt
            && in_array($transfer->status, $statuses, true);
    }

    /**
     * @param  list<string>  $statuses
     * @param  array<string, mixed>  $updates
     */
    public static function update(int $transferId, int $attempt, array $statuses, array $updates): int
    {
        return DownloadTransfer::query()
            ->whereKey($transferId)
            ->where('attempt', $attempt)
            ->whereIn('status', $statuses)
            ->update($updates);
    }

    /**
     * @param  list<string>  $statuses
     */
    public static function fresh(int $transferId, int $attempt, array $statuses): ?DownloadTransfer
    {
        return DownloadTransfer::query()
            ->with('file')
            ->whereKey($transferId)
            ->where('attempt', $attempt)
            ->whereIn('status', $statuses)
            ->first();
    }

    /**
     * @param  list<string>  $statuses
     * @param  Closure(DownloadTransfer): void  $callback
     */
    public static function runLocked(int $transferId, int $attempt, array $statuses, Closure $callback): bool
    {
        return DB::transaction(function () use ($transferId, $attempt, $statuses, $callback): bool {
            $transfer = DownloadTransfer::query()->with('file')->lockForUpdate()->find($transferId);
            if (! self::matches($transfer, $attempt, $statuses) || ! $transfer?->file) {
                return false;
            }

            $callback($transfer);

            return true;
        });
    }
}
