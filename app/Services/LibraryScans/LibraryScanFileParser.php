<?php

namespace App\Services\LibraryScans;

use App\Models\File;
use App\Models\FileMetadata;
use App\Services\Downloads\FileDownloadPreviewAssetGenerator;
use App\Support\AtlasPathResolver;
use App\Support\AtlasStorage;
use App\Support\FileMimeType;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class LibraryScanFileParser
{
    public function __construct(
        private readonly FileDownloadPreviewAssetGenerator $previewAssetGenerator,
        private readonly MediaProbeService $probe,
        private readonly AtlasStorage $appStorage,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function parse(File $file, string $parser, bool $regeneratePreviewAssets = false): array
    {
        $resolved = AtlasPathResolver::resolveExistingPath($file->path, [AtlasStorage::DISK]);
        if (! $resolved) {
            throw new \RuntimeException('Imported file is missing from Atlas app storage.');
        }

        $absolutePath = $resolved['full_path'];
        $mimeType = FileMimeType::canonicalize($file->mime_type ?? $this->detectMimeType($absolutePath));
        $probe = $this->probe->probe($absolutePath);
        $updates = [];
        $metadata = [
            'library_scan' => [
                'parser' => $parser,
                'parsed_at' => now()->toIso8601String(),
            ],
        ];

        if ($probe !== []) {
            $metadata['probe'] = $probe;
        }

        if ($parser === 'image' || $parser === 'video') {
            $updates = $regeneratePreviewAssets
                ? $this->previewAssetGenerator->regeneratePreviewAssets($file)
                : $this->previewAssetGenerator->generatePreviewAssets($file);
            if ($updates !== []) {
                $file->forceFill($updates)->save();
            }
        }

        if ($parser === 'audio') {
            $metadata['conversions'] = $this->normalizeAudioIfNeeded($file, $absolutePath, $mimeType);
        }

        if ($parser === 'video') {
            $metadata['conversions'] = [
                ...($metadata['conversions'] ?? []),
                ...$this->createVideoConversions($file, $absolutePath, $mimeType, $probe),
            ];
        }

        $this->mergeMetadata($file, $metadata);

        return [
            'updates' => $updates,
            'metadata' => $metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeAudioIfNeeded(File $file, string $absolutePath, ?string $mimeType): array
    {
        if (in_array($mimeType, ['audio/mpeg', 'audio/mp3'], true)) {
            return [];
        }

        $targetPath = $this->conversionPath($file, 'normalized', 'mp3');

        return $this->runFfmpegConversion([
            '-y',
            '-i',
            $absolutePath,
            '-vn',
            '-codec:a',
            'libmp3lame',
            '-q:a',
            '2',
            Storage::disk(AtlasStorage::DISK)->path($targetPath),
        ], 'normalized_audio', $targetPath);
    }

    /**
     * @return array<string, mixed>
     */
    private function createVideoConversions(File $file, string $absolutePath, ?string $mimeType, array $probe): array
    {
        $conversions = [];

        if ($this->shouldCreateStreamableVideo($mimeType, $probe)) {
            $targetPath = $this->conversionPath($file, 'streamable', 'mp4');
            $args = [
                '-y',
                '-i',
                $absolutePath,
            ];

            if ($this->hasOversizedVideoStream($probe)) {
                $args = [
                    ...$args,
                    '-vf',
                    "scale=w='min(1920,iw)':h='min(1080,ih)':force_original_aspect_ratio=decrease:force_divisible_by=2",
                ];
            }

            $conversions = [
                ...$conversions,
                ...$this->runFfmpegConversion([
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
                ], 'streamable_video', $targetPath),
            ];
        }

        return $conversions;
    }

    /**
     * @param  array<string, mixed>  $probe
     */
    private function shouldCreateStreamableVideo(?string $mimeType, array $probe): bool
    {
        if ($mimeType !== 'video/mp4') {
            return true;
        }

        $videoStream = $this->firstStreamOfType($probe, 'video');
        if ($videoStream === null) {
            return false;
        }

        $codec = strtolower((string) ($videoStream['codec_name'] ?? ''));
        if ($codec !== '' && ! in_array($codec, ['h264', 'avc1'], true)) {
            return true;
        }

        $audioStream = $this->firstStreamOfType($probe, 'audio');
        $audioCodec = strtolower((string) ($audioStream['codec_name'] ?? ''));
        if ($audioCodec !== '' && ! in_array($audioCodec, ['aac', 'mp3', 'mp4a'], true)) {
            return true;
        }

        return $this->hasOversizedVideoStream($probe);
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

    /**
     * @param  list<string>  $args
     * @return array<string, mixed>
     */
    private function runFfmpegConversion(array $args, string $key, string $targetPath): array
    {
        $ffmpeg = $this->probe->resolveFfmpegPath();
        if ($ffmpeg === null) {
            return [
                "{$key}_warning" => 'ffmpeg unavailable',
            ];
        }

        $disk = Storage::disk(AtlasStorage::DISK);
        $directory = dirname($targetPath);
        if (! $disk->exists($directory)) {
            $disk->makeDirectory($directory, 0755, true);
        }

        $process = new Process([$ffmpeg, ...$args]);
        $process->setTimeout((int) config('downloads.ffmpeg_timeout_seconds', 120));

        try {
            $process->run();
        } catch (\Throwable $e) {
            return [
                "{$key}_error" => $e->getMessage(),
            ];
        }

        if (! $process->isSuccessful() || ! $disk->exists($targetPath)) {
            return [
                "{$key}_error" => trim($process->getErrorOutput()) ?: 'ffmpeg conversion failed',
            ];
        }

        return [
            $key => $targetPath,
        ];
    }

    private function conversionPath(File $file, string $variant, string $extension): string
    {
        $filename = $this->appStorage->variantFilename((string) $file->filename, $variant, $extension);

        return $this->appStorage->segmentedPath(AtlasStorage::CONVERSIONS, $filename, $file->hash);
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
