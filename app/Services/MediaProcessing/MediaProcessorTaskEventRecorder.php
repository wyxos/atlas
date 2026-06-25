<?php

namespace App\Services\MediaProcessing;

use App\Enums\MediaProcessorOperation;
use App\Enums\MediaProcessorTaskStatus;
use App\Events\FilePreviewAssetsUpdated;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\MediaProcessorTask;
use App\Services\Library\LibraryIndexSyncDispatcher;
use App\Services\LibraryScans\LibraryScanService;

class MediaProcessorTaskEventRecorder
{
    public function __construct(
        private readonly MediaProcessorPathValidator $paths,
        private readonly LibraryScanService $libraryScans,
        private readonly LibraryIndexSyncDispatcher $libraryIndex,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(MediaProcessorTask $task, array $payload): void
    {
        $status = $this->normalizeStatus($payload['status'] ?? null);
        $progress = $this->normalizeProgress($payload['progress'] ?? null, $status);
        $result = $this->normalizeResult($payload);

        $updates = [
            'status' => $status,
            'phase' => is_string($payload['phase'] ?? null) ? $payload['phase'] : $status,
            'progress' => $progress,
            'result' => $result !== [] ? $result : $task->result,
            'last_event_at' => now(),
        ];

        if ($status === MediaProcessorTaskStatus::PROCESSING && ! $task->started_at) {
            $updates['started_at'] = now();
        }

        if ($status === MediaProcessorTaskStatus::COMPLETED) {
            $updates['completed_at'] = now();
            $updates['failed_at'] = null;
            $updates['error_code'] = null;
            $updates['error_message'] = null;
            $updates['error_context'] = null;
        }

        if ($status === MediaProcessorTaskStatus::FAILED) {
            $updates['failed_at'] = now();
            $updates['error_code'] = $this->sanitizeErrorCode($payload['error_code'] ?? null);
            $updates['error_message'] = $this->sanitizeErrorMessage($payload['error_message'] ?? null);
            $updates['error_context'] = $this->sanitizeErrorContext($payload['error_context'] ?? null);
        }

        $task->update($updates);
        $task = $task->fresh(['file', 'libraryScanMediaTask']) ?? $task;

        if ($status === MediaProcessorTaskStatus::COMPLETED) {
            $this->applyCompletedResult($task, $result);

            return;
        }

        if ($status === MediaProcessorTaskStatus::FAILED) {
            $this->markLibraryTaskFailed($task);

            return;
        }

        $this->markLibraryTaskProcessing($task, $updates['phase'], $progress);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeResult(array $payload): array
    {
        $result = is_array($payload['result'] ?? null) ? $payload['result'] : [];
        $outputPaths = is_array($result['output_paths'] ?? null)
            ? $result['output_paths']
            : (is_array($payload['output_paths'] ?? null) ? $payload['output_paths'] : []);

        if ($outputPaths !== []) {
            $result['output_paths'] = $this->paths->outputPaths($outputPaths);
        }

        return $result;
    }

    private function applyCompletedResult(MediaProcessorTask $task, array $result): void
    {
        $file = $task->file;
        if (! $file) {
            $this->markLibraryTaskFailed($task, 'missing_file', 'Media processor file is missing.');

            return;
        }

        $outputPaths = is_array($result['output_paths'] ?? null) ? $result['output_paths'] : [];
        $libraryResult = match ($task->operation) {
            MediaProcessorOperation::IMAGE_PREVIEW,
            MediaProcessorOperation::VIDEO_PREVIEW => $this->applyPreviewResult($file, $outputPaths, $result),
            MediaProcessorOperation::AUDIO_NORMALIZATION => $this->applyConversionResult($file, $outputPaths, 'normalized_audio'),
            MediaProcessorOperation::STREAMABLE_VIDEO => $this->applyConversionResult($file, $outputPaths, 'streamable_video'),
            default => [],
        };

        if ($libraryTask = $task->libraryScanMediaTask) {
            $this->libraryScans->markMediaTaskCompleted($libraryTask, $libraryResult);
        }
    }

    /**
     * @param  array<string, mixed>  $outputPaths
     * @param  array<string, mixed>  $result
     * @return array{updates: array<string, string>}
     */
    private function applyPreviewResult(File $file, array $outputPaths, array $result): array
    {
        $updates = [];
        foreach (['preview_path', 'poster_path'] as $key) {
            if (is_string($outputPaths[$key] ?? null) && $outputPaths[$key] !== '') {
                $updates[$key] = $outputPaths[$key];
            }
        }

        if ($updates !== []) {
            $file->forceFill($updates)->save();
            $this->libraryIndex->files([$file->id]);
            event(new FilePreviewAssetsUpdated((int) $file->id));
        }

        $metadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
        $this->persistImageDimensions($file, $metadata);

        return ['updates' => $updates];
    }

    /**
     * @param  array<string, mixed>  $outputPaths
     * @return array<string, string>
     */
    private function applyConversionResult(File $file, array $outputPaths, string $key): array
    {
        $path = $outputPaths[$key] ?? $outputPaths['conversion_path'] ?? null;
        if (! is_string($path) || $path === '') {
            return [];
        }

        $result = [$key => $path];
        $metadata = FileMetadata::query()->firstOrNew(['file_id' => $file->id]);
        $current = is_array($metadata->payload) ? $metadata->payload : [];
        $metadata->payload = array_replace_recursive($current, ['conversions' => $result]);
        $metadata->is_extracted = true;
        $metadata->save();

        return $result;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function persistImageDimensions(File $file, array $metadata): void
    {
        $width = is_numeric($metadata['width'] ?? null) ? (int) $metadata['width'] : 0;
        $height = is_numeric($metadata['height'] ?? null) ? (int) $metadata['height'] : 0;
        if ($width <= 0 || $height <= 0) {
            return;
        }

        $listing = is_array($file->listing_metadata) ? $file->listing_metadata : [];
        $listing['width'] = $width;
        $listing['height'] = $height;
        $file->listing_metadata = $listing;
        $file->save();

        $meta = FileMetadata::query()->firstOrNew(['file_id' => $file->id]);
        $payload = is_array($meta->payload) ? $meta->payload : [];
        $payload['width'] = $width;
        $payload['height'] = $height;
        $meta->payload = $payload;
        $meta->save();
    }

    private function markLibraryTaskProcessing(MediaProcessorTask $task, string $phase, int $progress): void
    {
        if ($libraryTask = $task->libraryScanMediaTask) {
            $this->libraryScans->markMediaTaskProcessing($libraryTask, $phase, $progress);
        }
    }

    private function markLibraryTaskFailed(
        MediaProcessorTask $task,
        ?string $code = null,
        ?string $message = null,
    ): void {
        if ($libraryTask = $task->libraryScanMediaTask) {
            $this->libraryScans->markMediaTaskFailed(
                $libraryTask,
                $code ?? $task->error_code ?? 'remote_media_processor_failed',
                $message ?? $task->error_message ?? 'Remote media processor failed.',
                ['media_processor_task_id' => $task->id],
            );
        }
    }

    private function normalizeStatus(mixed $status): string
    {
        return match ($status) {
            MediaProcessorTaskStatus::ACCEPTED => MediaProcessorTaskStatus::ACCEPTED,
            MediaProcessorTaskStatus::PROCESSING, 'started', 'progress' => MediaProcessorTaskStatus::PROCESSING,
            MediaProcessorTaskStatus::COMPLETED => MediaProcessorTaskStatus::COMPLETED,
            MediaProcessorTaskStatus::FAILED => MediaProcessorTaskStatus::FAILED,
            default => MediaProcessorTaskStatus::QUEUED,
        };
    }

    private function normalizeProgress(mixed $progress, string $status): int
    {
        if ($status === MediaProcessorTaskStatus::COMPLETED || $status === MediaProcessorTaskStatus::FAILED) {
            return 100;
        }

        return is_numeric($progress) ? max(0, min(99, (int) $progress)) : 1;
    }

    private function sanitizeErrorCode(mixed $code): string
    {
        if (! is_string($code) || $code === '') {
            return 'remote_media_processor_failed';
        }

        return substr(preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $code) ?? 'remote_media_processor_failed', 0, 120);
    }

    private function sanitizeErrorMessage(mixed $message): string
    {
        if (! is_string($message) || $message === '') {
            return 'Remote media processor failed.';
        }

        return mb_substr(str_replace(["\r", "\n"], ' ', $message), 0, 500);
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizeErrorContext(mixed $context): array
    {
        return is_array($context) ? array_filter($context, fn ($value): bool => is_scalar($value) || $value === null) : [];
    }
}
