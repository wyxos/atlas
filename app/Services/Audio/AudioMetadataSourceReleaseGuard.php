<?php

namespace App\Services\Audio;

class AudioMetadataSourceReleaseGuard
{
    public function __construct(
        private readonly AudioMetadataValueExtractor $values,
    ) {}

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array<string, mixed>  $candidateValues
     * @param  array<string, mixed>  $release
     */
    public function isLaterAlternateReleaseForCurrentSoundtrack(array $currentValues, array $candidateValues, array $release): bool
    {
        $currentAlbum = $this->values->cleanString($currentValues['album'] ?? null)
            ?? $this->values->cleanString($candidateValues['album'] ?? null);
        if (! $this->looksLikeSoundtrackAlbum($currentAlbum)) {
            return false;
        }

        $currentYear = $this->year($currentValues['release_date'] ?? null)
            ?? $this->year($candidateValues['release_date'] ?? null);
        $releaseYear = $this->year($release['released'] ?? $release['year'] ?? null);
        if ($currentYear === null || $releaseYear === null || $releaseYear <= $currentYear + 1) {
            return false;
        }

        $currentCountry = $this->values->cleanString($currentValues['release_country'] ?? null)
            ?? $this->values->cleanString($candidateValues['release_country'] ?? null);
        $releaseCountry = $this->values->cleanString($release['country'] ?? null);
        if ($currentCountry !== null && $releaseCountry !== null) {
            return $this->values->normalizeName($currentCountry) !== $this->values->normalizeName($releaseCountry);
        }

        return true;
    }

    private function looksLikeSoundtrackAlbum(?string $album): bool
    {
        if ($album === null) {
            return false;
        }

        $normalized = $this->values->normalizeName($album);

        return str_contains($normalized, 'soundtrack')
            || str_contains($normalized, 'original score')
            || str_contains($normalized, 'サウンドトラック')
            || str_contains($normalized, 'サントラ');
    }

    private function year(mixed $value): ?int
    {
        $value = $this->values->cleanString($value);
        if ($value === null || preg_match('/\b(19|20)\d{2}\b/', $value, $matches) !== 1) {
            return null;
        }

        return (int) $matches[0];
    }
}
