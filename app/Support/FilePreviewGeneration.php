<?php

namespace App\Support;

use App\Enums\MediaProcessorOperation;
use App\Enums\MediaProcessorTaskStatus;
use App\Models\File;
use App\Models\MediaProcessorTask;

class FilePreviewGeneration
{
    /**
     * @return list<string>
     */
    public static function operations(): array
    {
        return [
            MediaProcessorOperation::IMAGE_PREVIEW,
            MediaProcessorOperation::VIDEO_PREVIEW,
        ];
    }

    public static function shouldSuppressRemotePreviewUrl(File $file): bool
    {
        return self::isStoredPreviewableFile($file) && ! self::hasGeneratedPreview($file);
    }

    /**
     * @return array{
     *     status: string,
     *     can_retry: bool,
     *     message: string|null,
     *     task_id?: string|null,
     *     phase?: string|null,
     *     progress?: int|null
     * }|null
     */
    public static function state(File $file): ?array
    {
        if (! self::isStoredPreviewableFile($file)) {
            return null;
        }

        if (self::hasGeneratedPreview($file)) {
            return [
                'status' => 'ready',
                'can_retry' => false,
                'message' => null,
            ];
        }

        $task = self::latestTask($file);
        if (! $task) {
            return [
                'status' => 'missing',
                'can_retry' => true,
                'message' => 'Preview has not been generated yet.',
            ];
        }

        $status = is_string($task->status) && $task->status !== ''
            ? $task->status
            : MediaProcessorTaskStatus::QUEUED;

        return [
            'status' => $status,
            'can_retry' => $status === MediaProcessorTaskStatus::FAILED,
            'message' => $status === MediaProcessorTaskStatus::FAILED
                ? ($task->error_message ?: 'Preview generation failed.')
                : null,
            'task_id' => $task->id,
            'phase' => $task->phase,
            'progress' => $task->progress,
        ];
    }

    private static function isStoredPreviewableFile(File $file): bool
    {
        return (bool) $file->path
            && ((bool) $file->downloaded || $file->imported_at !== null)
            && (FileMimeType::isImage($file->mime_type) || FileMimeType::isVideo($file->mime_type));
    }

    private static function hasGeneratedPreview(File $file): bool
    {
        return self::hasPath($file->preview_path) || self::hasPath($file->poster_path);
    }

    private static function hasPath(mixed $path): bool
    {
        return is_string($path) && trim($path) !== '';
    }

    private static function latestTask(File $file): ?MediaProcessorTask
    {
        if ($file->relationLoaded('latestPreviewMediaProcessorTask')) {
            $task = $file->getRelation('latestPreviewMediaProcessorTask');

            return $task instanceof MediaProcessorTask ? $task : null;
        }

        if (! $file->exists) {
            return null;
        }

        return $file->latestPreviewMediaProcessorTask()->first();
    }
}
