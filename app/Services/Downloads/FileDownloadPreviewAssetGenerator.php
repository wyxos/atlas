<?php

namespace App\Services\Downloads;

use App\Enums\MediaProcessorOperation;
use App\Models\File;
use App\Models\LibraryScanMediaTask;
use App\Models\MediaProcessorTask;
use App\Services\MediaProcessing\RemoteMediaProcessorClient;
use App\Support\AtlasStorage;
use App\Support\FileMimeType;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

class FileDownloadPreviewAssetGenerator
{
    public function __construct(
        private readonly FileImagePreviewGenerator $imagePreviewGenerator,
        private readonly FileVideoPreviewGenerator $videoPreviewGenerator,

        private readonly RemoteMediaProcessorClient $remoteProcessor,
    ) {}

    /**
     * @return array{preview_path?: string, poster_path?: string}
     */
    public function generatePreviewAssets(File $file, ?LibraryScanMediaTask $libraryScanMediaTask = null): array
    {
        if (! $file->path) {
            return [];
        }

        $disk = Storage::disk(AtlasStorage::DISK);

        if (! $disk->exists($file->path)) {
            return [];
        }

        $absolutePath = $disk->path($file->path);
        $mimeType = FileMimeType::canonicalize($file->mime_type ?? $this->getMimeTypeFromFile($absolutePath));

        if ($remoteTask = $this->submitRemotePreviewIfNeeded($file, $absolutePath, $file->path, $mimeType, false, $libraryScanMediaTask)) {
            return $libraryScanMediaTask ? $this->remoteSubmissionResult($remoteTask) : [];
        }

        $updates = [];

        if ($this->isImageMimeType($mimeType) && ! $file->preview_path) {
            $this->imagePreviewGenerator->persistDimensions($file, $absolutePath);
            $previewPath = $this->imagePreviewGenerator->generateFromFile($disk, $absolutePath, $file->path);
            if ($previewPath) {
                $updates['preview_path'] = $previewPath;
            }
        }

        if ($this->isVideoMimeType($mimeType) && (! $file->preview_path || ! $file->poster_path)) {
            [$previewPath, $posterPath] = $this->videoPreviewGenerator->generate($disk, $absolutePath, $file->path);

            if ($previewPath && ! $file->preview_path) {
                $updates['preview_path'] = $previewPath;
            }
            if ($posterPath && ! $file->poster_path) {
                $updates['poster_path'] = $posterPath;
            }
        }

        return $updates;
    }

    /**
     * @return array{preview_path?: string, poster_path?: string}
     */
    public function regenerateVideoPreviewAssets(File $file): array
    {
        if (! $file->path) {
            return [];
        }

        $disk = Storage::disk(AtlasStorage::DISK);
        if (! $disk->exists($file->path)) {
            return [];
        }

        $absolutePath = $disk->path($file->path);
        $mimeType = FileMimeType::canonicalize($file->mime_type ?? $this->getMimeTypeFromFile($absolutePath));
        if (! $this->isVideoMimeType($mimeType)) {
            return [];
        }

        if ($this->submitRemotePreviewIfNeeded($file, $absolutePath, $file->path, $mimeType, true)) {
            return [];
        }

        [$previewPath, $posterPath] = $this->videoPreviewGenerator->generate($disk, $absolutePath, $file->path);

        $updates = [];
        if ($previewPath) {
            $updates['preview_path'] = $previewPath;
        }
        if ($posterPath) {
            $updates['poster_path'] = $posterPath;
        }

        return $updates;
    }

    /**
     * @return array{preview_path?: string, poster_path?: string}
     */
    public function regeneratePreviewAssets(File $file, ?LibraryScanMediaTask $libraryScanMediaTask = null): array
    {
        if (! $file->path) {
            return [];
        }

        $disk = Storage::disk(AtlasStorage::DISK);
        if (! $disk->exists($file->path)) {
            return [];
        }

        $absolutePath = $disk->path($file->path);
        $mimeType = FileMimeType::canonicalize($file->mime_type ?? $this->getMimeTypeFromFile($absolutePath));

        if ($remoteTask = $this->submitRemotePreviewIfNeeded($file, $absolutePath, $file->path, $mimeType, true, $libraryScanMediaTask)) {
            return $libraryScanMediaTask ? $this->remoteSubmissionResult($remoteTask) : [];
        }

        return $this->generateFinalizedPreviewAssets(
            $file,
            $disk,
            $absolutePath,
            $file->path,
            $mimeType,
        );
    }

    /**
     * @return array{preview_path?: string, poster_path?: string}
     */
    public function generateFinalizedPreviewAssets(
        File $file,
        Filesystem $disk,
        string $absolutePath,
        string $finalPath,
        ?string $mimeType,
        ?LibraryScanMediaTask $libraryScanMediaTask = null,
    ): array {
        if ($remoteTask = $this->submitRemotePreviewIfNeeded($file, $absolutePath, $finalPath, $mimeType, true, $libraryScanMediaTask)) {
            return $libraryScanMediaTask ? $this->remoteSubmissionResult($remoteTask) : [];
        }

        $updates = [];

        if ($this->isImageMimeType($mimeType)) {
            $this->imagePreviewGenerator->persistDimensions($file, $absolutePath);
            $previewPath = $this->imagePreviewGenerator->generateFromFile($disk, $absolutePath, $finalPath);
            if ($previewPath) {
                $updates['preview_path'] = $previewPath;
            }
        }

        if ($this->isVideoMimeType($mimeType)) {
            [$previewPath, $posterPath] = $this->videoPreviewGenerator->generate($disk, $absolutePath, $finalPath);

            if ($previewPath) {
                $updates['preview_path'] = $previewPath;
            }
            if ($posterPath) {
                $updates['poster_path'] = $posterPath;
            }
        }

        return $updates;
    }

    private function getMimeTypeFromFile(string $absolutePath): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (! $finfo) {
            return null;
        }

        $mimeType = finfo_file($finfo, $absolutePath) ?: null;
        finfo_close($finfo);

        return $mimeType && $mimeType !== 'application/octet-stream' ? $mimeType : null;
    }

    private function isImageMimeType(?string $mimeType): bool
    {
        if (! $mimeType) {
            return false;
        }

        $mimeType = strtolower($mimeType);

        return str_starts_with($mimeType, 'image/')
            && ! in_array($mimeType, ['image/svg+xml', 'image/x-icon'], true);
    }

    private function isVideoMimeType(?string $mimeType): bool
    {
        return FileMimeType::isVideo($mimeType);
    }

    private function submitRemotePreviewIfNeeded(
        File $file,
        string $absolutePath,
        string $sourcePath,
        ?string $mimeType,
        bool $force,
        ?LibraryScanMediaTask $libraryScanMediaTask = null,
    ): ?MediaProcessorTask {
        if (! $this->remoteProcessor->enabled()) {
            return null;
        }

        if ($this->isImageMimeType($mimeType) && ($force || ! $file->preview_path)) {
            $this->imagePreviewGenerator->persistDimensions($file, $absolutePath);
            $imageSize = @getimagesize($absolutePath);
            $originalWidth = is_array($imageSize) && isset($imageSize[0]) ? (int) $imageSize[0] : 0;
            $originalHeight = is_array($imageSize) && isset($imageSize[1]) ? (int) $imageSize[1] : 0;
            [$previewWidth, $previewHeight] = $originalWidth > 0 && $originalHeight > 0
                ? $this->imagePreviewGenerator->resolvePreviewDimensions($originalWidth, $originalHeight)
                : [(int) config('downloads.video_preview_width', 450), 0];

            return $this->remoteProcessor->submit(
                $file,
                MediaProcessorOperation::IMAGE_PREVIEW,
                $sourcePath,
                ['preview_path' => $this->imagePreviewGenerator->outputPath($absolutePath, $sourcePath)],
                [
                    'width' => $previewWidth,
                    'height' => $previewHeight,
                ],
                $libraryScanMediaTask,
            );

        }

        if ($this->isVideoMimeType($mimeType) && ($force || ! $file->preview_path || ! $file->poster_path)) {
            return $this->remoteProcessor->submit(
                $file,
                MediaProcessorOperation::VIDEO_PREVIEW,
                $sourcePath,
                $this->videoPreviewGenerator->outputPaths($sourcePath),
                $this->videoPreviewGenerator->previewOptions(),
                $libraryScanMediaTask,
            );

        }

        return null;
    }

    /**
     * @return array{remote_task_id: string, remote_status: string}
     */
    private function remoteSubmissionResult(MediaProcessorTask $task): array
    {
        return [
            'remote_task_id' => $task->id,
            'remote_status' => $task->status,
        ];
    }
}
