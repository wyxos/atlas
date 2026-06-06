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

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array<string, mixed>  $candidateValues
     * @param  array<string, mixed>|null  $release
     */
    public function isDifferentReleaseFamilyForCurrentCollection(array $currentValues, array $candidateValues, ?array $release = null): bool
    {
        $currentAlbum = $this->values->cleanString($currentValues['album'] ?? null);
        $proposedAlbum = $this->values->cleanString($release['title'] ?? null)
            ?? $this->values->cleanString($candidateValues['album'] ?? null);

        if ($currentAlbum === null || $proposedAlbum === null) {
            return false;
        }

        if ($this->sameAlbumFamily($currentAlbum, $proposedAlbum)) {
            return false;
        }

        return $this->looksLikeCollectionAlbum($currentAlbum);
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array<string, mixed>  $candidateValues
     */
    public function isSameFamilyButDifferentCurrentReleaseContext(array $currentValues, array $candidateValues): bool
    {
        $currentAlbum = $this->values->cleanString($currentValues['album'] ?? null);
        $proposedAlbum = $this->values->cleanString($candidateValues['album'] ?? null);

        if ($currentAlbum === null || $proposedAlbum === null) {
            return false;
        }

        if ($this->normalizedIdentity($currentAlbum) === $this->normalizedIdentity($proposedAlbum)) {
            return false;
        }

        if (! $this->sameAlbumFamily($currentAlbum, $proposedAlbum)) {
            return false;
        }

        return $this->hasDistinctiveReleaseContext($currentAlbum, $proposedAlbum);
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

    public function looksLikeCollectionAlbum(string $album): bool
    {
        $normalized = $this->normalizedWords($album);

        foreach ([
            'soundtrack',
            'ost',
            'original soundtrack',
            'tv animation',
            'animation',
            'sound collection',
            'score',
            'compilation',
            'collection',
            'volume',
            'vol',
            'disc',
            'cd',
            'edition',
            'mixed by',
            'remix',
            'remixed',
            'remixes',
        ] as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function sameAlbumFamily(string $currentAlbum, string $proposedAlbum): bool
    {
        if ($this->normalizedIdentity($currentAlbum) === $this->normalizedIdentity($proposedAlbum)) {
            return true;
        }

        $currentTokens = $this->albumFamilyTokens($currentAlbum);
        $proposedTokens = $this->albumFamilyTokens($proposedAlbum);
        if (count($currentTokens) < 2 || count($proposedTokens) < 2) {
            return false;
        }

        return count(array_intersect($currentTokens, $proposedTokens)) >= 2;
    }

    private function hasDistinctiveReleaseContext(string $currentAlbum, string $proposedAlbum): bool
    {
        $current = $this->normalizedWords($currentAlbum);
        $proposed = $this->normalizedWords($proposedAlbum);

        if (preg_match('/\b(19|20)\d{2}\b/', $current, $matches) === 1 && ! str_contains($proposed, $matches[0])) {
            return true;
        }

        foreach (['cd', 'disc', 'edition', 'mixed by', 'remix edition', 'volume', 'vol'] as $marker) {
            if (str_contains($current, $marker) && ! str_contains($proposed, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function albumFamilyTokens(string $album): array
    {
        $normalized = $this->normalizedWords($this->replaceNumberWords($album));
        $normalized = preg_replace('/(?<=\p{L})(?=\d)|(?<=\d)(?=\p{L})/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/(?<=\p{Latin})(?=[\p{Hiragana}\p{Katakana}\p{Han}])|(?<=[\p{Hiragana}\p{Katakana}\p{Han}])(?=\p{Latin})/u', ' ', $normalized) ?? $normalized;
        $tokens = preg_split('/\s+/', $normalized) ?: [];
        $stopWords = [
            'a',
            'an',
            'and',
            'by',
            'mixed',
            'pres',
            'presented',
            'presents',
            'the',
        ];

        $clean = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '' || in_array($token, $stopWords, true)) {
                continue;
            }

            if (preg_match('/^\d+$/', $token) === 1) {
                $token = (string) (int) $token;
            }

            $clean[$token] = $token;
        }

        return array_values($clean);
    }

    private function replaceNumberWords(string $value): string
    {
        return preg_replace_callback('/\b(one|two|three|four|five|six|seven|eight|nine|ten)\b/i', function (array $matches): string {
            return match (mb_strtolower($matches[1])) {
                'one' => '1',
                'two' => '2',
                'three' => '3',
                'four' => '4',
                'five' => '5',
                'six' => '6',
                'seven' => '7',
                'eight' => '8',
                'nine' => '9',
                'ten' => '10',
            };
        }, $value) ?? $value;
    }

    private function normalizedIdentity(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value)) ?? '';
    }

    private function normalizedWords(string $value): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($value)) ?? '');
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
