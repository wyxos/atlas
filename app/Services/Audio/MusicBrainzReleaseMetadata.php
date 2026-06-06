<?php

namespace App\Services\Audio;

use Illuminate\Support\Facades\Http;
use Throwable;

class MusicBrainzReleaseMetadata
{
    /**
     * @return array<string, mixed>
     */
    public function fetch(string $releaseId): array
    {
        $releaseId = trim($releaseId);
        if ($releaseId === '') {
            return [];
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['User-Agent' => $this->userAgent()])
                ->timeout((int) config('services.audio_metadata.http_timeout_seconds', 15))
                ->get(rtrim($this->musicBrainzBaseUrl(), '/').'/ws/2/release/'.$releaseId, [
                    'fmt' => 'json',
                    'inc' => 'labels media recordings artist-credits',
                ]);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>
     */
    public function values(array $release, ?string $recordingId = null): array
    {
        $values = [];

        $this->putIfPresent($values, 'album', $this->cleanString($release['title'] ?? null));
        $this->putIfPresent($values, 'release_date', $this->cleanString($release['date'] ?? null));
        $this->putIfPresent($values, 'release_country', $this->cleanString($release['country'] ?? null));
        $this->putIfPresent($values, 'barcode', $this->cleanString($release['barcode'] ?? null));
        $this->putIfPresent($values, 'release_label', $this->firstLabelName($release));
        $this->putIfPresent($values, 'catalog_number', $this->firstCatalogNumber($release));
        $this->putIfPresent($values, 'musicbrainz_release_id', $this->cleanString($release['id'] ?? null));

        foreach ($this->trackValues($release, $recordingId) as $key => $value) {
            $this->putIfPresent($values, $key, $value);
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    public function releaseSearchTitles(string $album): array
    {
        $clean = $this->cleanString($album);
        if ($clean === null) {
            return [];
        }

        $withoutSceneSuffix = preg_replace('/[-_\s]*\([A-Z0-9-]{2,}\)[-_\s]*(WEB|CD|VINYL|FLAC|MP3)?$/i', '', $clean) ?? $clean;
        $withoutFormatSuffix = preg_replace('/[-_\s]+(WEB|CD|VINYL|FLAC|MP3)$/i', '', $withoutSceneSuffix) ?? $withoutSceneSuffix;
        $spaced = str_replace(['__', '_'], ' ', $withoutFormatSuffix);
        $withoutEpSuffix = preg_replace('/\s+EP$/i', '', $spaced) ?? $spaced;

        return collect([$clean, $withoutSceneSuffix, $withoutFormatSuffix, $spaced, $withoutEpSuffix])
            ->map(fn (string $candidate): ?string => $this->cleanString($candidate))
            ->filter()
            ->unique(fn (string $candidate): string => $this->normalizeComparableString($candidate))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $release
     */
    public function canonicalTitle(array $release): ?string
    {
        return $this->cleanString($release['title'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $release
     */
    private function firstLabelName(array $release): ?string
    {
        $labels = data_get($release, 'label-info.*.label.name', []);

        return collect(is_array($labels) ? $labels : [])
            ->map(fn (mixed $label): ?string => $this->cleanString($label))
            ->filter()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $release
     */
    private function firstCatalogNumber(array $release): ?string
    {
        $catalogNumbers = data_get($release, 'label-info.*.catalog-number', []);

        return collect(is_array($catalogNumbers) ? $catalogNumbers : [])
            ->map(fn (mixed $catalogNumber): ?string => $this->cleanString($catalogNumber))
            ->filter(fn (?string $catalogNumber): bool => $catalogNumber !== null && mb_strtolower($catalogNumber) !== '[none]')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, string>
     */
    private function trackValues(array $release, ?string $recordingId): array
    {
        $recordingId = $this->cleanString($recordingId);
        if ($recordingId === null) {
            return [];
        }

        $media = $release['media'] ?? null;
        if (! is_array($media)) {
            return [];
        }

        foreach ($media as $medium) {
            if (! is_array($medium) || ! is_array($medium['tracks'] ?? null)) {
                continue;
            }

            foreach ($medium['tracks'] as $track) {
                if (! is_array($track)) {
                    continue;
                }

                if ($this->cleanString(data_get($track, 'recording.id')) !== $recordingId) {
                    continue;
                }

                return array_filter([
                    'track_number' => $this->cleanString($track['number'] ?? $track['position'] ?? null),
                    'disc_number' => $this->cleanString($medium['position'] ?? null),
                ], fn (?string $value): bool => $value !== null);
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function putIfPresent(array &$values, string $key, mixed $value): void
    {
        if ($value === null || $value === []) {
            return;
        }

        $values[$key] = $value;
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $clean = preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';

        return $clean !== '' ? $clean : null;
    }

    private function normalizeComparableString(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($value))) ?? '';
    }

    private function musicBrainzBaseUrl(): string
    {
        return (string) config('services.audio_metadata.musicbrainz_api_base_url', 'https://musicbrainz.org');
    }

    private function userAgent(): string
    {
        return (string) config('services.audio_metadata.user_agent', 'Atlas/1.0');
    }
}
