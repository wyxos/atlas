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
        $pageUrl = trim((string) ($payload['referrer_url'] ?? ''));
        $downloadVia = $payload['download_via'] ?? null;

        // Canonical file identity is url. referrer_url is provenance and may be non-unique.
        $url = $this->stripFragment($url);
        $referrerUrl = $pageUrl !== '' ? $pageUrl : $url;
        $urlHash = $url !== '' ? hash('sha256', $url) : null;
        $filename = $this->resolveFilename($payload['filename'] ?? null, $url);
        $ext = $payload['ext'] ?? FileTypeDetector::extensionFromUrl($url);
        $mimeType = $payload['mime_type'] ?? FileTypeDetector::mimeFromUrl($url);

        $metadata = array_filter([
            'page_url' => $pageUrl !== '' ? $pageUrl : null,
            'page_title' => $payload['page_title'] ?? null,
            'tag_name' => $payload['tag_name'] ?? null,
            'alt' => $payload['alt'] ?? null,
            'width' => $payload['width'] ?? null,
            'height' => $payload['height'] ?? null,
            'download_via' => $downloadVia,
        ], fn ($value) => $value !== null && $value !== '');

        $file = null;
        if ($urlHash !== null) {
            $file = File::query()
                ->where('url_hash', $urlHash)
                ->where('url', $url)
                ->first();
        }
        if (! $file) {
            $file = File::query()
                ->where('url', $url)
                ->orderByDesc('downloaded')
                ->orderByDesc('id')
                ->first();
        }

        $isNew = $file === null;
        if (! $file) {
            $file = new File;
            $file->source = $payload['source'] ?? 'Extension';
            $file->source_id = $payload['source_id'] ?? null;
            $file->filename = $filename;
        }

        $existingMetadata = $file->listing_metadata;
        if (! is_array($existingMetadata)) {
            $existingMetadata = [];
        }

        $mergedMetadata = [
            ...$existingMetadata,
            ...$metadata,
        ];
        $mergedMetadata = array_filter($mergedMetadata, fn ($value) => $value !== null && $value !== '');

        $updates = array_filter([
            'url' => $url,
            'referrer_url' => $referrerUrl,
            'filename' => $filename,
            'ext' => $ext,
            'mime_type' => $mimeType,
            'title' => $payload['title'] ?? null,
            'description' => $payload['description'] ?? null,
            'preview_url' => $payload['preview_url'] ?? null,
            'size' => $payload['size'] ?? null,
            'listing_metadata' => $mergedMetadata !== [] ? $mergedMetadata : null,
        ], fn ($value) => $value !== null && $value !== '');

        // Avoid clobbering known file metadata with null guesses from page URLs.
        if (empty($ext)) {
            unset($updates['ext']);
        }
        if (empty($mimeType)) {
            unset($updates['mime_type']);
        }
        if (! isset($updates['size']) || ! is_numeric($updates['size']) || (int) $updates['size'] <= 0) {
            unset($updates['size']);
        }

        $file->fill($updates);
        $file->save();

        if ($isNew) {
            $metrics = app(MetricsService::class);
            $metrics->incrementMetric(MetricsService::KEY_FILES_TOTAL, 1);
            $metrics->incrementMetric(MetricsService::KEY_FILES_UNREACTED_NOT_BLACKLISTED, 1);
        }

        $queued = false;
        if ($file && $file->url_hash) {
            File::query()
                ->where('url_hash', $file->url_hash)
                ->where('url', $file->url)
                ->whereKeyNot($file->id)
                ->delete();
        }

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

    private function stripFragment(string $url): string
    {
        $hashPos = strpos($url, '#');
        if ($hashPos === false) {
            return $url;
        }

        return substr($url, 0, $hashPos);
    }
}
