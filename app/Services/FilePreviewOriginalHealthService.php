<?php

namespace App\Services;

use App\Models\File;
use App\Services\LibraryScans\MediaProbeService;
use App\Support\AtlasPathResolver;
use App\Support\AtlasStorage;
use App\Support\FileMimeType;
use Throwable;

class FilePreviewOriginalHealthService
{
    public const string MISSING_PATH = 'missing_path';

    public const string MISSING_DISK_FILE = 'missing_disk_file';

    public const string EMPTY_DISK_FILE = 'empty_disk_file';

    public const string SIZE_MISMATCH = 'size_mismatch';

    public const string UNREADABLE_IMAGE = 'unreadable_image';

    public const string UNREADABLE_VIDEO = 'unreadable_video';

    public const string UNSUPPORTED_MIME = 'unsupported_mime';

    public function __construct(
        private readonly MediaProbeService $probe,
    ) {}

    /**
     * @return array{
     *     previewable: bool,
     *     healthy: bool,
     *     reason_codes: list<string>,
     *     expected_size: int|null,
     *     actual_size: int|null,
     *     message: string|null,
     *     recommended_action: string
     * }
     */
    public function inspect(File $file): array
    {
        $expectedSize = $this->positiveInt($file->size);
        $actualSize = null;
        $reasonCodes = [];

        if (! $this->isPreviewable($file)) {
            return $this->result(
                previewable: false,
                reasonCodes: [self::UNSUPPORTED_MIME],
                expectedSize: $expectedSize,
                actualSize: null,
                recommendedAction: 'manual_review',
            );
        }

        if (! is_string($file->path) || trim($file->path) === '') {
            return $this->result(
                previewable: true,
                reasonCodes: [self::MISSING_PATH],
                expectedSize: $expectedSize,
                actualSize: null,
                recommendedAction: $this->recommendedAction($file),
            );
        }

        $resolved = AtlasPathResolver::resolveExistingPath($file->path, [AtlasStorage::DISK]);
        if (! $resolved) {
            return $this->result(
                previewable: true,
                reasonCodes: [self::MISSING_DISK_FILE],
                expectedSize: $expectedSize,
                actualSize: null,
                recommendedAction: $this->recommendedAction($file),
            );
        }

        $actualSize = $this->nonNegativeInt($resolved['size']);
        if ($actualSize === null) {
            $actualSize = $this->nonNegativeInt(@filesize($resolved['full_path']));
        }

        if ($actualSize === 0) {
            $reasonCodes[] = self::EMPTY_DISK_FILE;
        }

        if ($expectedSize !== null && $actualSize !== null && $expectedSize !== $actualSize) {
            $reasonCodes[] = self::SIZE_MISMATCH;
        }

        if (FileMimeType::isImage($file->mime_type)) {
            if (@getimagesize($resolved['full_path']) === false) {
                $reasonCodes[] = self::UNREADABLE_IMAGE;
            }
        } elseif (FileMimeType::isVideo($file->mime_type) && ! $this->hasVideoStream($resolved['full_path'])) {
            $reasonCodes[] = self::UNREADABLE_VIDEO;
        }

        return $this->result(
            previewable: true,
            reasonCodes: array_values(array_unique($reasonCodes)),
            expectedSize: $expectedSize,
            actualSize: $actualSize,
            recommendedAction: $this->recommendedAction($file),
        );
    }

    public function isPreviewable(File $file): bool
    {
        $mimeType = FileMimeType::canonicalize($file->mime_type);

        return (FileMimeType::isImage($mimeType) && ! in_array($mimeType, ['image/svg+xml', 'image/x-icon'], true))
            || FileMimeType::isVideo($mimeType);
    }

    /**
     * @param  list<string>  $reasonCodes
     * @return array{
     *     previewable: bool,
     *     healthy: bool,
     *     reason_codes: list<string>,
     *     expected_size: int|null,
     *     actual_size: int|null,
     *     message: string|null,
     *     recommended_action: string
     * }
     */
    private function result(
        bool $previewable,
        array $reasonCodes,
        ?int $expectedSize,
        ?int $actualSize,
        string $recommendedAction,
    ): array {
        $reasonCodes = array_values(array_unique($reasonCodes));

        return [
            'previewable' => $previewable,
            'healthy' => $previewable && $reasonCodes === [],
            'reason_codes' => $reasonCodes,
            'expected_size' => $expectedSize,
            'actual_size' => $actualSize,
            'message' => $this->message($reasonCodes),
            'recommended_action' => $recommendedAction,
        ];
    }

    /**
     * @param  list<string>  $reasonCodes
     */
    private function message(array $reasonCodes): ?string
    {
        $primary = $reasonCodes[0] ?? null;

        return match ($primary) {
            self::MISSING_PATH => 'Original file path is missing.',
            self::MISSING_DISK_FILE => 'Original file is missing from storage.',
            self::EMPTY_DISK_FILE => 'Original file is empty.',
            self::SIZE_MISMATCH => 'Original file size does not match the database record.',
            self::UNREADABLE_IMAGE => 'Original image could not be read.',
            self::UNREADABLE_VIDEO => 'Original video could not be read.',
            self::UNSUPPORTED_MIME => 'This file type does not support generated previews.',
            default => null,
        };
    }

    private function recommendedAction(File $file): string
    {
        if (strtolower(trim((string) $file->source)) === 'local' || $file->imported_at !== null || (bool) $file->not_found) {
            return 'mark_preview_unavailable';
        }

        if ($this->hasRemoteSourceUrl($file) && ((bool) $file->downloaded || $file->downloaded_at !== null)) {
            return 'redownload_original';
        }

        return 'manual_review';
    }

    private function hasRemoteSourceUrl(File $file): bool
    {
        foreach ([$file->referrer_url, $file->preview_url, $file->url] as $url) {
            if (! is_string($url) || trim($url) === '') {
                continue;
            }

            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (in_array($scheme, ['http', 'https'], true)) {
                return true;
            }
        }

        return false;
    }

    private function hasVideoStream(string $absolutePath): bool
    {
        try {
            $probe = $this->probe->probe($absolutePath);
        } catch (Throwable) {
            return false;
        }

        $streams = is_array($probe['streams'] ?? null) ? $probe['streams'] : [];

        foreach ($streams as $stream) {
            if (is_array($stream) && ($stream['codec_type'] ?? null) === 'video') {
                return true;
            }
        }

        return false;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value >= 0 ? $value : null;
    }
}
