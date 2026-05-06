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
    public function parse(File $file, string $parser): array
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
            $updates = $this->previewAssetGenerator->generatePreviewAssets($file);
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
                ...$this->createVideoConversions($file, $absolutePath, $mimeType),
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
    private function createVideoConversions(File $file, string $absolutePath, ?string $mimeType): array
    {
        $conversions = [];

        if ($mimeType !== 'video/mp4') {
            $targetPath = $this->conversionPath($file, 'streamable', 'mp4');
            $conversions = [
                ...$conversions,
                ...$this->runFfmpegConversion([
                    '-y',
                    '-i',
                    $absolutePath,
                    '-c:v',
                    'libx264',
                    '-preset',
                    'veryfast',
                    '-crf',
                    '23',
                    '-c:a',
                    'aac',
                    '-movflags',
                    '+faststart',
                    Storage::disk(AtlasStorage::DISK)->path($targetPath),
                ], 'streamable_video', $targetPath),
            ];
        }

        $seekbarPath = $this->adjacentPreviewPath($file, 'seekbar', 'jpg');
        $conversions = [
            ...$conversions,
            ...$this->runFfmpegConversion([
                '-y',
                '-i',
                $absolutePath,
                '-vf',
                'fps=1/10,scale=160:-1,tile=10x10',
                '-frames:v',
                '1',
                Storage::disk(AtlasStorage::DISK)->path($seekbarPath),
            ], 'seekbar_preview', $seekbarPath),
        ];

        return $conversions;
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

    private function adjacentPreviewPath(File $file, string $variant, string $extension): string
    {
        $path = trim((string) $file->path);
        $directory = pathinfo($path, PATHINFO_DIRNAME);
        $base = pathinfo(basename($path), PATHINFO_FILENAME) ?: 'file';
        $filename = $this->appStorage->storedFilename("{$base}.{$variant}", $extension);

        return $directory !== '' && $directory !== '.'
            ? "{$directory}/{$filename}"
            : $filename;
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
