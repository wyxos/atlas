<?php

namespace App\Services;

use App\Enums\MediaProcessorOperation;
use App\Enums\MediaProcessorTaskStatus;
use App\Events\FilePreviewAssetsUpdated;
use App\Models\File;
use App\Models\MediaProcessorTask;
use App\Support\FileMimeType;
use App\Support\FilePreviewGeneration;
use Illuminate\Support\Str;

class FilePreviewRepairService
{
    public const string ACTION_PREVIEW_QUEUED = 'preview_queued';

    public const string ACTION_REDOWNLOAD_QUEUED = 'redownload_queued';

    public const string ACTION_UNAVAILABLE = 'unavailable';

    public const string ERROR_ORIGINAL_MISSING = 'preview_original_missing';

    public const string ERROR_ORIGINAL_CORRUPT = 'preview_original_corrupt';

    public const string ERROR_REDOWNLOAD_NOT_FOUND = 'preview_redownload_not_found';

    public const string ERROR_REDOWNLOAD_UNSUPPORTED = 'preview_redownload_unsupported';

    public function __construct(
        private readonly FileRedownloadService $redownloads,
    ) {}

    /**
     * @param  array{
     *     previewable: bool,
     *     healthy: bool,
     *     reason_codes: list<string>,
     *     expected_size: int|null,
     *     actual_size: int|null,
     *     message: string|null,
     *     recommended_action: string
     * }  $health
     * @return array{queued: bool, action: string, file: File}
     */
    public function repairUnhealthyOriginal(File $file, array $health, ?int $userId): array
    {
        $redownload = $this->redownloads->queueForPreviewOriginalRepair($file, $userId);
        $file = $redownload['file'];

        if (($redownload['queued'] ?? false) === true) {
            event(new FilePreviewAssetsUpdated((int) $file->id));

            return [
                'queued' => true,
                'action' => self::ACTION_REDOWNLOAD_QUEUED,
                'file' => $file,
            ];
        }

        $errorCode = (bool) ($redownload['not_found'] ?? false)
            ? self::ERROR_REDOWNLOAD_NOT_FOUND
            : self::ERROR_REDOWNLOAD_UNSUPPORTED;
        $message = $this->unavailableMessage($health, $errorCode, (bool) ($redownload['checked'] ?? true));

        $this->recordUnavailable($file, $health, $errorCode, $message);
        $file->refresh()->load('latestPreviewMediaProcessorTask');

        event(new FilePreviewAssetsUpdated((int) $file->id));

        return [
            'queued' => false,
            'action' => self::ACTION_UNAVAILABLE,
            'file' => $file,
        ];
    }

    /**
     * @param  array{
     *     reason_codes: list<string>,
     *     expected_size: int|null,
     *     actual_size: int|null,
     *     message: string|null,
     *     recommended_action: string
     * }  $health
     */
    public function recordUnavailable(File $file, array $health, string $errorCode, string $message): MediaProcessorTask
    {
        return MediaProcessorTask::query()->create([
            'id' => (string) Str::uuid(),
            'file_id' => $file->id,
            'operation' => $this->operation($file),
            'status' => MediaProcessorTaskStatus::FAILED,
            'phase' => FilePreviewGeneration::UNAVAILABLE_STATUS,
            'progress' => 100,
            'storage_profile' => 'atlas-local',
            'input_path' => is_string($file->path) ? $file->path : '',
            'output_paths' => [],
            'attempts' => 1,
            'failed_at' => now(),
            'last_event_at' => now(),
            'error_code' => $errorCode,
            'error_message' => $message,
            'error_context' => [
                'reason_codes' => $health['reason_codes'] ?? [],
                'expected_size' => $health['expected_size'] ?? null,
                'actual_size' => $health['actual_size'] ?? null,
                'recommended_action' => $health['recommended_action'] ?? null,
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    public static function nonRetryableErrorCodes(): array
    {
        return [
            self::ERROR_ORIGINAL_MISSING,
            self::ERROR_ORIGINAL_CORRUPT,
            self::ERROR_REDOWNLOAD_NOT_FOUND,
            self::ERROR_REDOWNLOAD_UNSUPPORTED,
        ];
    }

    /**
     * @param  array{reason_codes?: list<string>, message?: string|null}  $health
     */
    private function unavailableMessage(array $health, string $errorCode, bool $checked): string
    {
        if ($errorCode === self::ERROR_REDOWNLOAD_NOT_FOUND) {
            return 'Original file is missing and the remote source is no longer available.';
        }

        if (! $checked) {
            return 'Original file is missing and the remote source could not be checked.';
        }

        $reasonCodes = $health['reason_codes'] ?? [];
        $isCorrupt = array_intersect($reasonCodes, [
            FilePreviewOriginalHealthService::EMPTY_DISK_FILE,
            FilePreviewOriginalHealthService::SIZE_MISMATCH,
            FilePreviewOriginalHealthService::UNREADABLE_IMAGE,
            FilePreviewOriginalHealthService::UNREADABLE_VIDEO,
        ]) !== [];

        return $isCorrupt
            ? 'Original file is corrupted and cannot be re-downloaded automatically.'
            : 'Original file is missing and cannot be re-downloaded automatically.';
    }

    private function operation(File $file): string
    {
        return FileMimeType::isVideo($file->mime_type)
            ? MediaProcessorOperation::VIDEO_PREVIEW
            : MediaProcessorOperation::IMAGE_PREVIEW;
    }
}
