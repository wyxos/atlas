<?php

namespace App\Support;

use App\Models\File;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class CivitaiMediaResolver
{
    public function resolve(File $file): CivitaiResolution
    {
        $resolution = new CivitaiResolution(false, false, null, null, null, null);

        if (strcasecmp((string) $file->source, 'CivitAI') !== 0) {
            return $resolution;
        }

        $extractor = new CivitaiVideoUrlExtractor;

        try {
            $sample = $extractor->extractFromFileId($file->id);
        } catch (\Throwable $e) {
            $sample = null;
        }

        if ($sample === '404_NOT_FOUND') {
            $resolution->notFound = true;
        } elseif (is_string($sample) && filter_var($sample, FILTER_VALIDATE_URL)) {
            $probe = $this->probeVideoUrl($sample);
            if ($probe['ok']) {
                return new CivitaiResolution(
                    true,
                    false,
                    $probe['url'],
                    $probe['mime'],
                    $probe['bytes'],
                    'extractor'
                );
            }

            if ($probe['not_found']) {
                $resolution->notFound = true;
            }
        }

        foreach ($this->metadataCandidates($file) as $candidate) {
            $probe = $this->probeVideoUrl($candidate);

            if ($probe['ok']) {
                return new CivitaiResolution(
                    true,
                    false,
                    $probe['url'],
                    $probe['mime'],
                    $probe['bytes'],
                    'metadata'
                );
            }

            if ($probe['not_found']) {
                $resolution->notFound = true;
            }
        }

        $currentUrl = (string) $file->url;
        if ($currentUrl !== '' && filter_var($currentUrl, FILTER_VALIDATE_URL)) {
            $probe = $this->probeVideoUrl($currentUrl);

            if ($probe['ok']) {
                return new CivitaiResolution(
                    true,
                    false,
                    $probe['url'],
                    $probe['mime'],
                    $probe['bytes'],
                    'current'
                );
            }

            if ($probe['not_found']) {
                $resolution->notFound = true;
            }
        }

        return $resolution;
    }

    public function resolveAndUpdate(File $file): CivitaiResolution
    {
        $resolution = $this->resolve($file);

        if ($resolution->found && $resolution->url !== null) {
            $updates = [
                'url' => $resolution->url,
                'thumbnail_url' => $resolution->url, // Update thumbnail_url to the resolved video URL as well
                'mime_type' => $resolution->mime ?? $file->mime_type,
                'not_found' => false,
            ];

            $file->forceFill($updates)->saveQuietly();

            try {
                $file->searchable();
            } catch (\Throwable $e) {
                // ignore indexing issues
            }

            return $resolution->markUpdated();
        }

        if ($resolution->notFound) {
            $file->forceFill(['not_found' => true])->saveQuietly();

            try {
                $file->searchable();
            } catch (\Throwable $e) {
                // ignore indexing issues
            }

            return $resolution;
        }

        return $resolution;
    }

    /**
     * @return array<int, string>
     */
    protected function metadataCandidates(File $file): array
    {
        $listing = $this->normalizeArray($file->listing_metadata);
        $detail = $this->normalizeArray($file->detail_metadata);

        $candidates = array_filter([
            (string) $file->thumbnail_url,
            Arr::get($listing, 'url'),
            Arr::get($detail, 'url'),
            Arr::get($detail, 'downloadUrl'),
        ], fn ($value) => is_string($value) && $value !== '');

        $unique = [];
        foreach ($candidates as $candidate) {
            if (! filter_var($candidate, FILTER_VALIDATE_URL)) {
                continue;
            }

            $unique[$candidate] = true;
        }

        return array_keys($unique);
    }

    /**
     * @param  mixed  $candidate
     */
    protected function normalizeArray($candidate): array
    {
        if (is_array($candidate)) {
            return $candidate;
        }

        if (is_string($candidate)) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @return array{ok: bool, url?: string, mime?: string, bytes?: int|null, not_found: bool}
     */
    protected function probeVideoUrl(string $url): array
    {
        $result = [
            'ok' => false,
            'not_found' => false,
        ];

        try {
            $head = Http::timeout(20)->head($url);

            if ($head->status() === 404) {
                $result['not_found'] = true;

                return $result;
            }

            if ($head->ok()) {
                $mime = strtolower((string) ($head->header('Content-Type') ?? ''));
                if ($this->isVideoMime($mime)) {
                    $result['ok'] = true;
                    $result['url'] = $url;
                    $result['mime'] = $mime ?: $this->guessMimeFromUrl($url);
                    $result['bytes'] = $this->parseContentLength($head->header('Content-Length'));

                    return $result;
                }
            }
        } catch (\Throwable $e) {
            // fall through to range probe
        }

        try {
            $probe = Http::timeout(30)
                ->withHeaders(['Range' => 'bytes=0-0'])
                ->get($url);

            if ($probe->status() === 404) {
                $result['not_found'] = true;

                return $result;
            }

            if (in_array($probe->status(), [200, 206], true)) {
                $mime = strtolower((string) ($probe->header('Content-Type') ?? ''));
                if ($this->isVideoMime($mime)) {
                    $result['ok'] = true;
                    $result['url'] = $url;
                    $result['mime'] = $mime ?: $this->guessMimeFromUrl($url);
                    $result['bytes'] = $this->parseContentLength($probe->header('Content-Length'));

                    return $result;
                }
            }
        } catch (\Throwable $e) {
            // ignore failures
        }

        return $result;
    }

    protected function isVideoMime(?string $mime): bool
    {
        return is_string($mime) && str_starts_with($mime, 'video/');
    }

    protected function guessMimeFromUrl(string $url): ?string
    {
        $lower = strtolower($url);

        if (str_ends_with($lower, '.mp4')) {
            return 'video/mp4';
        }

        if (str_ends_with($lower, '.webm')) {
            return 'video/webm';
        }

        if (str_ends_with($lower, '.mov')) {
            return 'video/quicktime';
        }

        return null;
    }

    /**
     * @param  string|string[]|null  $value
     */
    protected function parseContentLength($value): ?int
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || ! ctype_digit($trimmed)) {
            return null;
        }

        return (int) $trimmed;
    }
}

class CivitaiResolution
{
    public function __construct(
        public bool $found,
        public bool $notFound,
        public ?string $url,
        public ?string $mime,
        public ?int $bytes,
        public ?string $origin,
        public bool $updated = false,
    ) {}

    public function markUpdated(): self
    {
        $this->updated = true;

        return $this;
    }
}
