<?php

namespace App\Services;

use App\Jobs\DownloadFile;
use App\Models\File;
use App\Support\FileTypeDetector;
use Illuminate\Support\Str;

class ExternalFileIngestService
{
    public function ingest(array $payload, bool $queueDownload = true): array
    {
        $url = trim((string) $payload['url']);
        $originalUrl = trim((string) ($payload['original_url'] ?? $url));
        $pageUrl = $payload['referrer_url'] ?? null;

        $referrerKey = $originalUrl !== '' ? $originalUrl : $url;
        $filename = $this->resolveFilename($payload['filename'] ?? null, $url);
        $ext = $payload['ext'] ?? FileTypeDetector::extensionFromUrl($url);
        $mimeType = $payload['mime_type'] ?? FileTypeDetector::mimeFromUrl($url);

        $metadata = array_filter([
            'page_url' => $pageUrl,
            'page_title' => $payload['page_title'] ?? null,
            'tag_name' => $payload['tag_name'] ?? null,
            'alt' => $payload['alt'] ?? null,
            'width' => $payload['width'] ?? null,
            'height' => $payload['height'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $now = now();
        $fileRow = [
            'source' => $payload['source'] ?? 'Extension',
            'source_id' => $payload['source_id'] ?? null,
            'url' => $url,
            'referrer_url' => $referrerKey,
            'filename' => $filename,
            'ext' => $ext,
            'mime_type' => $mimeType,
            'title' => $payload['title'] ?? null,
            'description' => $payload['description'] ?? null,
            'preview_url' => $payload['preview_url'] ?? null,
            'size' => $payload['size'] ?? null,
            'listing_metadata' => empty($metadata) ? null : json_encode($metadata),
            'detail_metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $isNew = ! File::query()->where('referrer_url', $referrerKey)->exists();

        File::upsert(
            [$fileRow],
            ['referrer_url'],
            ['url', 'filename', 'ext', 'mime_type', 'title', 'description', 'preview_url', 'size', 'listing_metadata', 'updated_at']
        );

        if ($isNew) {
            $metrics = app(MetricsService::class);
            $metrics->incrementMetric(MetricsService::KEY_FILES_TOTAL, 1);
            $metrics->incrementMetric(MetricsService::KEY_FILES_UNREACTED_NOT_BLACKLISTED, 1);
        }

        $file = File::query()->where('referrer_url', $referrerKey)->first();
        $queued = false;

        if ($queueDownload && $file && ! $file->downloaded && $file->url) {
            DownloadFile::dispatch($file->id);
            $queued = true;
        }

        return [
            'file' => $file,
            'created' => $isNew,
            'queued' => $queued,
        ];
    }

    private function resolveFilename(?string $candidate, string $url): string
    {
        $candidate = trim((string) $candidate);
        if ($candidate !== '') {
            return $this->sanitizeFilename($candidate);
        }

        $path = parse_url($url, PHP_URL_PATH);
        $base = $path ? pathinfo($path, PATHINFO_FILENAME) : '';
        $base = $this->sanitizeFilename($base);

        return $base !== '' ? $base : Str::random(40);
    }

    private function sanitizeFilename(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        $name = preg_replace('/[\\\\\\/\\:\\*\\?\"<>\\|]+/', '-', $name) ?? $name;
        $name = preg_replace('/\\s+/', ' ', $name) ?? $name;

        $name = trim($name);
        if ($name === '') {
            return '';
        }

        return substr($name, 0, 200);
    }
}
