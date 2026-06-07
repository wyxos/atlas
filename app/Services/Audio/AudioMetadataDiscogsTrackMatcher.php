<?php

namespace App\Services\Audio;

class AudioMetadataDiscogsTrackMatcher
{
    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>|null
     */
    public function bestTrack(array $release, ?string $title, ?int $duration): ?array
    {
        if ($title === null) {
            return null;
        }

        $tracks = $release['tracklist'] ?? null;
        if (! is_array($tracks)) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($tracks as $track) {
            if (! is_array($track)) {
                continue;
            }

            $trackTitle = $this->cleanString($track['title'] ?? null);
            if ($trackTitle === null) {
                continue;
            }

            $score = $this->trackMatchScore($title, $trackTitle);
            $trackDuration = $this->durationSeconds($track['duration'] ?? null);
            if ($duration !== null && $trackDuration !== null && abs($duration - $trackDuration) <= 5) {
                $score += 2;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $track;
            }
        }

        return $bestScore >= 8 ? $best : null;
    }

    private function trackMatchScore(string $title, string $trackTitle): int
    {
        $left = $this->normalizeTrackTitle($title);
        $right = $this->normalizeTrackTitle($trackTitle);
        if ($left === '' || $right === '') {
            return 0;
        }

        if ($left === $right) {
            return 10;
        }

        if (str_contains($left, $right) || str_contains($right, $left)) {
            return 8;
        }

        $leftTokens = $this->distinctTrackTokens($left);
        $rightTokens = $this->distinctTrackTokens($right);
        $sharedTokens = array_values(array_intersect($leftTokens, $rightTokens));

        if (count($sharedTokens) >= 4 && $this->mixDescriptorsCompatible($leftTokens, $rightTokens)) {
            return count($sharedTokens) >= min(count($leftTokens), count($rightTokens)) - 1 ? 9 : 8;
        }

        return 0;
    }

    private function normalizeTrackTitle(string $title): string
    {
        $title = preg_replace('/^\s*\d+\s*[-_.]\s*/', '', $title) ?? $title;

        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($title)) ?? '');
    }

    /**
     * @return list<string>
     */
    private function distinctTrackTokens(string $title): array
    {
        return array_values(array_diff(
            array_unique(array_filter(explode(' ', $title))),
            ['a', 'an', 'and', 'the']
        ));
    }

    /**
     * @param  list<string>  $leftTokens
     * @param  list<string>  $rightTokens
     */
    private function mixDescriptorsCompatible(array $leftTokens, array $rightTokens): bool
    {
        $descriptors = ['remix', 'mix', 'edit', 'version', 'instrumental', 'dub', 'extended', 'radio'];
        $leftDescriptors = array_values(array_intersect($leftTokens, $descriptors));
        $rightDescriptors = array_values(array_intersect($rightTokens, $descriptors));

        return $leftDescriptors === []
            || $rightDescriptors === []
            || array_intersect($leftDescriptors, $rightDescriptors) !== [];
    }

    private function durationSeconds(mixed $duration): ?int
    {
        $duration = $this->cleanString($duration);
        if ($duration === null || ! str_contains($duration, ':')) {
            return null;
        }

        $parts = array_map('intval', explode(':', $duration));
        if (count($parts) === 2) {
            return ($parts[0] * 60) + $parts[1];
        }

        if (count($parts) === 3) {
            return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
        }

        return null;
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $clean = preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';

        return $clean !== '' ? $clean : null;
    }
}
