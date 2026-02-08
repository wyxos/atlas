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
        $downloadVia = $payload['download_via'] ?? null;
        $tagName = $payload['tag_name'] ?? null;

        // The extension's check endpoint uses the "url" field, but the DB dedupe key is `files.referrer_url`.
        // For yt-dlp (page-based downloads), the stable identifier should be the page URL, not a per-trigger client key.
        $referrerKey = $originalUrl !== '' ? $originalUrl : $url;
        if ($downloadVia === 'yt-dlp' && in_array($tagName, ['video', 'iframe'], true)) {
            $referrerKey = $url !== '' ? $url : $referrerKey;
        }

        $referrerKey = $this->stripFragment($referrerKey);
        $url = $this->stripFragment($url);
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
            'download_via' => $downloadVia,
        ], fn ($value) => $value !== null && $value !== '');

        $file = File::query()->where('referrer_url', $referrerKey)->first();

        if (! $file && $downloadVia === 'yt-dlp' && in_array($tagName, ['video', 'iframe'], true)) {
            // Repair older client-key based duplicates by promoting one row to the stable referrer key.
            $dupe = File::query()
                ->where('url', $url)
                ->where('referrer_url', 'like', $url.'#atlas-ext-video=%')
                ->orderByDesc('downloaded')
                ->orderByDesc('id')
                ->first();

            if ($dupe) {
                $dupe->forceFill([
                    'referrer_url' => $referrerKey,
                ])->save();

                $file = $dupe->refresh();
            }
        }

        $isNew = $file === null;
        if (! $file) {
            $file = new File;
            $file->referrer_url = $referrerKey;
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

        if ($downloadVia === 'yt-dlp' && in_array($tagName, ['video', 'iframe'], true)) {
            // Cleanup duplicates from the earlier client-key based scheme.
            // Example: https://site/video#atlas-ext-video=... should not create multiple DB rows.
            File::query()
                ->where('url', $url)
                ->where('referrer_url', 'like', $url.'#atlas-ext-video=%')
                ->whereKeyNot($file?->id)
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
