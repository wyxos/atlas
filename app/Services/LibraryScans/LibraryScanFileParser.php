<?php

namespace App\Services\LibraryScans;

use App\Enums\LibraryScanMediaTask;
use App\Models\File;
use App\Models\FileMetadata;
use App\Support\AtlasPathResolver;
use App\Support\AtlasStorage;
use App\Support\FileMimeType;

class LibraryScanFileParser
{
    public function __construct(
        private readonly MediaProbeService $probe,
        private readonly BrowserVideoSupport $browserVideoSupport,
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
        $metadata = [
            'library_scan' => [
                'parser' => $parser,
                'parsed_at' => now()->toIso8601String(),
                'regenerate_preview_assets' => $regeneratePreviewAssets,
            ],
        ];

        if ($probe !== []) {
            $metadata['probe'] = $probe;
        }

        $this->mergeMetadata($file, $metadata);

        return [
            'updates' => [],
            'metadata' => $metadata,
            'tasks' => $this->mediaTasksFor($parser, $mimeType, $probe),
        ];
    }

    /**
     * @param  array<string, mixed>  $probe
     * @return list<string>
     */
    private function mediaTasksFor(string $parser, ?string $mimeType, array $probe): array
    {
        return match ($parser) {
            'image' => [LibraryScanMediaTask::TASK_PREVIEW_ASSETS],
            'audio' => [LibraryScanMediaTask::TASK_AUDIO_NORMALIZATION],
            'video' => [
                LibraryScanMediaTask::TASK_PREVIEW_ASSETS,
                ...($this->browserVideoSupport->shouldCreateStreamableVideo($mimeType, $probe)
                    ? [LibraryScanMediaTask::TASK_VIDEO_STREAMABLE]
                    : []),
            ],
            default => [],
        };
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
