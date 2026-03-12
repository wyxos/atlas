<?php

namespace App\Services\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Models\DownloadTransfer;
use App\Models\File;

final class DownloadTransferActionAvailability
{
    /**
     * @return array{can_resume:bool,can_restart:bool}
     */
    public static function forPayload(DownloadTransfer $transfer): array
    {
        return [
            'can_resume' => self::canResume($transfer),
            'can_restart' => self::canRestart($transfer),
        ];
    }

    public static function canResume(DownloadTransfer $transfer): bool
    {
        if ($transfer->status === DownloadTransferStatus::PAUSED) {
            return true;
        }

        return $transfer->status === DownloadTransferStatus::FAILED
            && self::isYtDlpTransfer($transfer)
            && ! self::errorRequiresRestart($transfer->error);
    }

    public static function canRestart(DownloadTransfer $transfer): bool
    {
        return in_array($transfer->status, [
            DownloadTransferStatus::FAILED,
            DownloadTransferStatus::CANCELED,
            DownloadTransferStatus::COMPLETED,
        ], true);
    }

    public static function errorRequiresRestart(?string $message): bool
    {
        $lower = strtolower(trim((string) $message));
        if ($lower === '') {
            return false;
        }

        return str_contains($lower, 'use restart to fetch the file from scratch')
            || str_contains($lower, 'atlas discarded the temporary yt-dlp fragments')
            || str_contains($lower, '.ytdl file is corrupt')
            || str_contains($lower, 'downloaded file is empty')
            || (
                str_contains($lower, 'unable to rename file')
                && str_contains($lower, '.part-frag')
            );
    }

    private static function isYtDlpTransfer(DownloadTransfer $transfer): bool
    {
        $file = self::transferFile($transfer);

        return data_get($file?->listing_metadata, 'download_via') === 'yt-dlp';
    }

    private static function transferFile(DownloadTransfer $transfer): ?File
    {
        $file = $transfer->relationLoaded('file') ? $transfer->file : null;
        if ($file instanceof File && array_key_exists('listing_metadata', $file->getAttributes())) {
            return $file;
        }

        return $transfer->file()
            ->select(['id', 'listing_metadata'])
            ->first();
    }
}
