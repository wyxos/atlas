<?php

namespace App\Services\Audio;

class AudioMetadataCandidateGuard
{
    public function __construct(
        private readonly AudioMetadataSourceReleaseGuard $sourceReleases,
    ) {}

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     */
    public function allows(array $currentValues, array $candidate): bool
    {
        return ! $this->looksLikeTrackTitleSingleForCollection($currentValues, $candidate)
            && ! $this->sourceReleases->isDifferentReleaseFamilyForCurrentCollection($currentValues, $candidate['values'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     */
    public function requiresAiReviewForFingerprintReleaseDrift(array $currentValues, array $candidate): bool
    {
        if (($candidate['provider'] ?? null) !== 'acoustid_musicbrainz') {
            return false;
        }

        return $this->sourceReleases->isSameFamilyButDifferentCurrentReleaseContext($currentValues, $candidate['values'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     */
    private function looksLikeTrackTitleSingleForCollection(array $currentValues, array $candidate): bool
    {
        $currentAlbum = $this->cleanString($currentValues['album'] ?? null);
        $proposedAlbum = $this->cleanString($candidate['values']['album'] ?? null);

        if ($currentAlbum === null || $proposedAlbum === null) {
            return false;
        }

        if ($this->sameIdentity($currentAlbum, $proposedAlbum)) {
            return false;
        }

        if (! $this->sourceReleases->looksLikeCollectionAlbum($currentAlbum)) {
            return false;
        }

        if (! $this->proposedAlbumMatchesTrackIdentity($proposedAlbum, $currentValues, $candidate)) {
            return false;
        }

        return ! $this->hasStrongAlbumEvidence($candidate);
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     */
    private function proposedAlbumMatchesTrackIdentity(string $proposedAlbum, array $currentValues, array $candidate): bool
    {
        foreach ([
            $candidate['values']['title'] ?? null,
            $currentValues['title'] ?? null,
        ] as $title) {
            $title = $this->cleanString($title);
            if ($title !== null && $this->sameIdentity($proposedAlbum, $title)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     */
    private function hasStrongAlbumEvidence(array $candidate): bool
    {
        $matchedFields = $this->stringList($candidate['evidence']['matched_existing_fields'] ?? []);

        return in_array('album', $matchedFields, true);
    }

    private function sameIdentity(string $left, string $right): bool
    {
        return $this->normalizedIdentity($left) === $this->normalizedIdentity($right);
    }

    private function normalizedIdentity(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value)) ?? '';
    }

    private function normalizedWords(string $value): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($value)) ?? '');
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $clean = preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';

        return $clean !== '' ? $clean : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            $value = [$value];
        }

        return array_values(array_filter(
            array_map(fn (mixed $entry): ?string => $this->cleanString($entry), $value),
            fn (?string $entry): bool => $entry !== null,
        ));
    }
}
