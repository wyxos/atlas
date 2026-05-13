<?php

namespace App\Services\LibraryScans;

use App\Enums\MediaProcessorOperation;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\LibraryScanMediaTask;
use App\Services\Downloads\FileDownloadPreviewAssetGenerator;
use App\Services\MediaProcessing\RemoteMediaProcessorClient;
use App\Support\AtlasPathResolver;
use App\Support\AtlasStorage;
use App\Support\FileMimeType;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class LibraryScanMediaProcessor
{
    public function __construct(
        private readonly FileDownloadPreviewAssetGenerator $previewAssetGenerator,
        private readonly MediaProbeService $probe,
        private readonly AtlasStorage $appStorage,
        private readonly RemoteMediaProcessorClient $remoteProcessor,
        private readonly BrowserVideoSupport $browserVideoSupport,
    ) {}

    /**
     * @return array{updates: array<string, mixed>}
     */
    public function generatePreviewAssets(
        File $file,
        bool $regeneratePreviewAssets = false,
        ?LibraryScanMediaTask $libraryScanMediaTask = null,
    ): array {
        $remoteAwareTask = $this->remoteProcessor->enabled() ? $libraryScanMediaTask : null;

        if ($regeneratePreviewAssets) {
            $result = $remoteAwareTask
                ? $this->previewAssetGenerator->regeneratePreviewAssets($file, $remoteAwareTask)
                : $this->previewAssetGenerator->regeneratePreviewAssets($file);
        } else {
            $result = $remoteAwareTask
                ? $this->previewAssetGenerator->generatePreviewAssets($file, $remoteAwareTask)
                : $this->previewAssetGenerator->generatePreviewAssets($file);
        }

        if (isset($result['remote_task_id'])) {
            return $result;
        }

        $updates = $result;

        if ($updates !== []) {
            $file->forceFill($updates)->save();
        }

        return [
            'updates' => $updates,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizeAudio(File $file, ?LibraryScanMediaTask $libraryScanMediaTask = null): array
    {
        $targetPath = $this->conversionPath($file, 'mp3');

        if ($this->remoteProcessor->enabled()) {
            return $this->submitRemoteConversion(
                $file,
                $libraryScanMediaTask,
                MediaProcessorOperation::AUDIO_NORMALIZATION,
                'normalized_audio',
                $targetPath,
                [
                    'audio_codec' => 'libmp3lame',
                    'quality' => 2,
                ],
            );
        }

        $resolved = $this->resolveFilePath($file);
        $result = $this->runFfmpegConversion([
            '-y',
            '-i',
            $resolved,
            '-vn',
            '-codec:a',
            'libmp3lame',
            '-q:a',
            '2',
            Storage::disk(AtlasStorage::DISK)->path($targetPath),
        ], 'normalized_audio', $targetPath);

        $this->mergeMetadata($file, ['conversions' => $result]);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function createStreamableVideo(File $file, ?LibraryScanMediaTask $libraryScanMediaTask = null): array
    {
        $resolved = $this->resolveFilePath($file);
        $mimeType = FileMimeType::canonicalize($file->mime_type ?? $this->detectMimeType($resolved));
        $probe = $this->probe->probe($resolved);

        if (! $this->browserVideoSupport->shouldCreateStreamableVideo($mimeType, $probe)) {
            return [];
        }

        $targetPath = $this->conversionPath($file, 'mp4');

        if ($this->remoteProcessor->enabled()) {
            return $this->submitRemoteConversion(
                $file,
                $libraryScanMediaTask,
                MediaProcessorOperation::STREAMABLE_VIDEO,
                'streamable_video',
                $targetPath,
                [
                    'max_width' => 1920,
                    'max_height' => 1080,
                    'video_codec' => 'libx264',
                    'audio_codec' => 'aac',
                ],
            );
        }

        $args = [
            '-y',
            '-i',
            $resolved,
        ];

        if ($this->hasOversizedVideoStream($probe)) {
            $args = [
                ...$args,
                '-vf',
                "scale=w='min(1920,iw)':h='min(1080,ih)':force_original_aspect_ratio=decrease:force_divisible_by=2",
            ];
        }

        $result = $this->runFfmpegConversion([
            ...$args,
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-crf',
            '23',
            '-pix_fmt',
            'yuv420p',
            '-c:a',
            'aac',
            '-movflags',
            '+faststart',
            Storage::disk(AtlasStorage::DISK)->path($targetPath),
        ], 'streamable_video', $targetPath);

        $this->mergeMetadata($file, ['conversions' => $result]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function submitRemoteConversion(
        File $file,
        ?LibraryScanMediaTask $libraryScanMediaTask,
        string $operation,
        string $outputKey,
        string $targetPath,
        array $options = [],
    ): array {
        $task = $this->remoteProcessor->submit(
            $file,
            $operation,
            (string) $file->path,
            [$outputKey => $targetPath],
            $options,
            $libraryScanMediaTask,
        );

        return [
            'remote_task_id' => $task->id,
            'remote_status' => $task->status,
        ];
    }

    private function resolveFilePath(File $file): string
    {
        $resolved = AtlasPathResolver::resolveExistingPath($file->path, [AtlasStorage::DISK]);
        if (! $resolved) {
            throw new RuntimeException('Imported file is missing from Atlas app storage.');
        }

        return $resolved['full_path'];
    }

    /**
     * @param  list<string>  $args
     * @return array<string, mixed>
     */
    private function runFfmpegConversion(array $args, string $key, string $targetPath): array
    {
        $ffmpeg = $this->probe->resolveFfmpegPath();
        if ($ffmpeg === null) {
            throw new RuntimeException('ffmpeg unavailable');
        }

        $disk = Storage::disk(AtlasStorage::DISK);
        $directory = dirname($targetPath);
        if (! $disk->exists($directory)) {
            $disk->makeDirectory($directory, 0755, true);
        }

        $process = new Process([$ffmpeg, ...$args]);
        $process->setTimeout((int) config('downloads.library_scan_conversion_timeout_seconds', 21600));

        try {
            $process->run();
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), previous: $e);
        }

        if (! $process->isSuccessful() || ! $disk->exists($targetPath)) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'ffmpeg conversion failed');
        }

        return [
            $key => $targetPath,
        ];
    }

    private function conversionPath(File $file, string $extension): string
    {
        $filename = $this->appStorage->filenameWithExtension(basename((string) $file->path), $extension);

        return $this->appStorage->derivedPath((string) $file->path, 'conversions', $filename);
    }

    /**
     * @param  array<string, mixed>  $probe
     */
    private function hasOversizedVideoStream(array $probe): bool
    {
        $videoStream = $this->firstStreamOfType($probe, 'video');
        if ($videoStream === null) {
            return false;
        }

        $width = is_numeric($videoStream['width'] ?? null) ? (int) $videoStream['width'] : 0;
        $height = is_numeric($videoStream['height'] ?? null) ? (int) $videoStream['height'] : 0;

        return $width > 1920 || $height > 1080;
    }

    /**
     * @param  array<string, mixed>  $probe
     * @return array<string, mixed>|null
     */
    private function firstStreamOfType(array $probe, string $type): ?array
    {
        $streams = is_array($probe['streams'] ?? null) ? $probe['streams'] : [];
        foreach ($streams as $stream) {
            if (! is_array($stream) || ($stream['codec_type'] ?? null) !== $type) {
                continue;
            }

            return $stream;
        }

        return null;
    }

    private function detectMimeType(string $absolutePath): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $mimeType = finfo_file($finfo, $absolutePath) ?: null;
        finfo_close($finfo);

        return $mimeType;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function mergeMetadata(File $file, array $payload): void
    {
        $metadata = FileMetadata::query()->firstOrNew(['file_id' => $file->id]);
        $current = is_array($metadata->payload) ? $metadata->payload : [];

        $metadata->payload = array_replace_recursive($current, $payload);
        $metadata->is_extracted = true;
        $metadata->save();
    }
}
