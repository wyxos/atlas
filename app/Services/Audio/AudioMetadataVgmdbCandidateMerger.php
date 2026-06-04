<?php

namespace App\Services\Audio;

class AudioMetadataVgmdbCandidateMerger
{
    /**
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}|null  $vgmdbCandidate
     * @return array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}
     */
    public function merge(array $candidate, ?array $vgmdbCandidate, string $provider): array
    {
        if ($vgmdbCandidate === null) {
            return $candidate;
        }

        foreach ($vgmdbCandidate['values'] as $key => $value) {
            if (! array_key_exists($key, $candidate['values']) || $candidate['values'][$key] === null || $candidate['values'][$key] === []) {
                $candidate['values'][$key] = $value;
            }
        }

        $candidate['provider'] = $provider;
        $candidate['confidence'] = min(96, max($candidate['confidence'], $vgmdbCandidate['confidence']) + 1);
        $candidate['evidence']['vgmdb_album_id'] = $vgmdbCandidate['evidence']['vgmdb_album_id'] ?? null;
        $candidate['evidence']['vgmdb_album_link'] = $vgmdbCandidate['evidence']['vgmdb_album_link'] ?? null;
        $candidate['evidence']['vgmdb_source'] = $vgmdbCandidate['evidence']['source'] ?? null;

        if (($candidate['evidence']['cover_source'] ?? null) === null && ($vgmdbCandidate['evidence']['cover_source'] ?? null) !== null) {
            $candidate['evidence']['cover_source'] = $vgmdbCandidate['evidence']['cover_source'];
        }

        return $candidate;
    }
}
