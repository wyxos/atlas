<?php

namespace App\Support;

class SpotifyTrack
{
    public static function isSource(?string $source): bool
    {
        return strtolower(trim((string) $source)) === 'spotify';
    }

    public static function uri(?string $source, ?string $sourceId, mixed ...$urlCandidates): ?string
    {
        if (! self::isSource($source)) {
            return null;
        }

        $sourceId = trim((string) $sourceId);
        if (preg_match('/^spotify:track:([A-Za-z0-9]{22})$/', $sourceId) === 1) {
            return $sourceId;
        }

        if (preg_match('/^[A-Za-z0-9]{22}$/', $sourceId) === 1) {
            return 'spotify:track:'.$sourceId;
        }

        foreach ($urlCandidates as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            if (preg_match('/spotify:track:([A-Za-z0-9]{22})/', $candidate, $matches) === 1) {
                return 'spotify:track:'.$matches[1];
            }

            if (preg_match('#open\.spotify\.com/track/([A-Za-z0-9]{22})#', $candidate, $matches) === 1) {
                return 'spotify:track:'.$matches[1];
            }
        }

        return null;
    }
}
