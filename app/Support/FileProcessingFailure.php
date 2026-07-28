<?php

namespace App\Support;

use App\Enums\DownloadTransferStatus;
use App\Enums\LibraryScanMediaTask as LibraryScanMediaTaskType;
use App\Enums\MediaProcessorOperation;
use App\Enums\MediaProcessorTaskStatus;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\LibraryScanMediaTask;
use App\Models\MediaProcessorTask;
use Carbon\CarbonInterface;

final class FileProcessingFailure
{
    /**
     * @return array{
     *     stage: string,
     *     title: string,
     *     message: string,
     *     error_code: string|null,
     *     occurred_at: string|null
     * }|null
     */
    public static function state(File $file): ?array
    {
        $failures = array_values(array_filter([
            self::downloadFailure($file),
            self::libraryConversionFailure($file),
            self::standaloneConversionFailure($file),
        ]));

        if ($failures === []) {
            return null;
        }

        usort(
            $failures,
            static fn (array $left, array $right): int => $right['sort_timestamp'] <=> $left['sort_timestamp'],
        );

        $failure = $failures[0];
        unset($failure['sort_timestamp']);

        return $failure;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function downloadFailure(File $file): ?array
    {
        $transfer = self::latestDownloadTransfer($file);
        if (! $transfer || $transfer->status !== DownloadTransferStatus::FAILED) {
            return null;
        }

        return self::failure(
            stage: 'download',
            title: 'Download failed',
            message: $transfer->error ?: 'The file could not be downloaded.',
            errorCode: null,
            occurredAt: $transfer->failed_at ?? $transfer->updated_at,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function libraryConversionFailure(File $file): ?array
    {
        $task = self::latestLibraryConversionTask($file);
        if (! $task || $task->status !== LibraryScanMediaTaskType::STATUS_FAILED) {
            return null;
        }

        return self::failure(
            stage: self::stageForLibraryTask($task->type),
            title: self::titleForLibraryTask($task->type),
            message: $task->error_message ?: 'The media conversion failed.',
            errorCode: $task->error_code,
            occurredAt: $task->updated_at,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function standaloneConversionFailure(File $file): ?array
    {
        $task = self::latestStandaloneConversionTask($file);
        if (! $task || $task->status !== MediaProcessorTaskStatus::FAILED) {
            return null;
        }

        return self::failure(
            stage: self::stageForProcessorOperation($task->operation),
            title: self::titleForProcessorOperation($task->operation),
            message: $task->error_message ?: 'The media conversion failed.',
            errorCode: $task->error_code,
            occurredAt: $task->updated_at,
        );
    }

    /**
     * @return array{
     *     stage: string,
     *     title: string,
     *     message: string,
     *     error_code: string|null,
     *     occurred_at: string|null,
     *     sort_timestamp: int
     * }
     */
    private static function failure(
        string $stage,
        string $title,
        string $message,
        ?string $errorCode,
        mixed $occurredAt,
    ): array {
        $date = $occurredAt instanceof CarbonInterface ? $occurredAt : null;

        return [
            'stage' => $stage,
            'title' => $title,
            'message' => $message,
            'error_code' => $errorCode,
            'occurred_at' => $date?->toIso8601String(),
            'sort_timestamp' => $date?->getTimestamp() ?? 0,
        ];
    }

    private static function latestDownloadTransfer(File $file): ?DownloadTransfer
    {
        if ($file->relationLoaded('latestDownloadTransfer')) {
            $transfer = $file->getRelation('latestDownloadTransfer');

            return $transfer instanceof DownloadTransfer ? $transfer : null;
        }

        return $file->exists ? $file->latestDownloadTransfer()->first() : null;
    }

    private static function latestLibraryConversionTask(File $file): ?LibraryScanMediaTask
    {
        if ($file->relationLoaded('latestLibraryConversionTask')) {
            $task = $file->getRelation('latestLibraryConversionTask');

            return $task instanceof LibraryScanMediaTask ? $task : null;
        }

        return $file->exists ? $file->latestLibraryConversionTask()->first() : null;
    }

    private static function latestStandaloneConversionTask(File $file): ?MediaProcessorTask
    {
        if ($file->relationLoaded('latestStandaloneConversionMediaProcessorTask')) {
            $task = $file->getRelation('latestStandaloneConversionMediaProcessorTask');

            return $task instanceof MediaProcessorTask ? $task : null;
        }

        return $file->exists ? $file->latestStandaloneConversionMediaProcessorTask()->first() : null;
    }

    private static function stageForLibraryTask(string $type): string
    {
        return match ($type) {
            LibraryScanMediaTaskType::TASK_AUDIO_NORMALIZATION => 'audio_conversion',
            LibraryScanMediaTaskType::TASK_VIDEO_STREAMABLE => 'video_conversion',
            default => 'media_conversion',
        };
    }

    private static function titleForLibraryTask(string $type): string
    {
        return match ($type) {
            LibraryScanMediaTaskType::TASK_AUDIO_NORMALIZATION => 'Audio conversion failed',
            LibraryScanMediaTaskType::TASK_VIDEO_STREAMABLE => 'Video conversion failed',
            default => 'Media conversion failed',
        };
    }

    private static function stageForProcessorOperation(string $operation): string
    {
        return match ($operation) {
            MediaProcessorOperation::AUDIO_NORMALIZATION => 'audio_conversion',
            MediaProcessorOperation::STREAMABLE_VIDEO => 'video_conversion',
            default => 'media_conversion',
        };
    }

    private static function titleForProcessorOperation(string $operation): string
    {
        return match ($operation) {
            MediaProcessorOperation::AUDIO_NORMALIZATION => 'Audio conversion failed',
            MediaProcessorOperation::STREAMABLE_VIDEO => 'Video conversion failed',
            default => 'Media conversion failed',
        };
    }
}
