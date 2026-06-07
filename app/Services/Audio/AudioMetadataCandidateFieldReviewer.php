<?php

namespace App\Services\Audio;

use App\Models\File;

class AudioMetadataCandidateFieldReviewer
{
    private const RELEASE_PACKAGE_FIELDS = [
        'album',
        'cover_url',
        'track_number',
        'disc_number',
        'release_label',
        'catalog_number',
        'barcode',
        'release_date',
        'release_country',
        'musicbrainz_release_id',
        'discogs_release_id',
    ];

    public function __construct(
        private readonly AudioMetadataAiReviewer $aiReviewer,
        private readonly AudioMetadataValueExtractor $values,
    ) {}

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     * @param  array<string, array{current:mixed,proposed:mixed}>  $changes
     * @return array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}|null
     */
    public function review(File $file, array $currentValues, array $candidate, array $changes): ?array
    {
        if ($changes === []) {
            return null;
        }

        if ($this->needsAiFieldReview($currentValues, $candidate, $changes)) {
            $review = $this->aiReviewer->reviewFields($file, $currentValues, $candidate, $changes);
            if ($review !== null) {
                $candidate['evidence']['field_review'] = $review;
                $candidate['values'] = $this->valuesAllowedByReview($candidate['values'], $review);

                return $candidate['values'] === [] ? null : $candidate;
            }
        }

        $candidate['values'] = $this->valuesAllowedByRules($currentValues, $candidate);

        return $candidate['values'] === [] ? null : $candidate;
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     * @param  array<string, array{current:mixed,proposed:mixed}>  $changes
     */
    private function needsAiFieldReview(array $currentValues, array $candidate, array $changes): bool
    {
        if (! $this->aiReviewer->enabled()) {
            return false;
        }

        if (! str_starts_with((string) ($candidate['provider'] ?? ''), 'acoustid_musicbrainz')) {
            return false;
        }

        if (($candidate['evidence']['ai_review']['verdict'] ?? null) === 'accept') {
            return false;
        }

        if (($candidate['evidence']['release_consistency_review']['verdict'] ?? null) === 'accept') {
            return false;
        }

        return $this->hasReleasePackageChange($changes)
            || $this->hasConflictingTitleDescriptor($currentValues, $candidate)
            || $this->addsArtistsForAmbiguousRelease($currentValues, $candidate);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array{verdict:string,confidence:float|null,reason:string,model:string|null,safe_fields:list<string>}  $review
     * @return array<string, mixed>
     */
    private function valuesAllowedByReview(array $values, array $review): array
    {
        $safeFields = $review['safe_fields'] ?? [];
        if ($safeFields !== []) {
            return array_intersect_key($values, array_flip($safeFields));
        }

        return ($review['verdict'] ?? null) === 'accept' ? $values : [];
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     * @return array<string, mixed>
     */
    private function valuesAllowedByRules(array $currentValues, array $candidate): array
    {
        $values = $candidate['values'];

        if ($this->requiresReleasePackagePruning($candidate) && ! $this->releasePackageHasSupport($currentValues, $candidate)) {
            foreach (self::RELEASE_PACKAGE_FIELDS as $field) {
                unset($values[$field]);
            }
        }

        if ($this->hasConflictingTitleDescriptor($currentValues, $candidate)) {
            unset($values['title']);
        }

        if ($this->addsArtistsForAmbiguousRelease($currentValues, $candidate)) {
            unset($values['artists']);
        }

        return $values;
    }

    /**
     * @param  array<string, array{current:mixed,proposed:mixed}>  $changes
     */
    private function hasReleasePackageChange(array $changes): bool
    {
        return array_intersect(array_keys($changes), self::RELEASE_PACKAGE_FIELDS) !== [];
    }

    /**
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     */
    private function requiresReleasePackagePruning(array $candidate): bool
    {
        return str_starts_with((string) ($candidate['provider'] ?? ''), 'acoustid_musicbrainz');
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     */
    private function releasePackageHasSupport(array $currentValues, array $candidate): bool
    {
        if (($candidate['provider'] ?? null) === 'existing_album_cover') {
            return true;
        }

        if (($candidate['evidence']['ai_review']['verdict'] ?? null) === 'accept') {
            return true;
        }

        if (($candidate['evidence']['release_consistency_review']['verdict'] ?? null) === 'accept') {
            return true;
        }

        $matchedFields = $this->stringList($candidate['evidence']['matched_existing_fields'] ?? []);
        if (in_array('album', $matchedFields, true)) {
            return true;
        }

        $currentAlbum = $this->values->cleanString($currentValues['album'] ?? null);
        if ($currentAlbum === null) {
            return true;
        }

        return (($candidate['evidence']['identity_support'] ?? null) === 'strong_fingerprint_release'
            && ! $this->hasDistinctAlbumContext($currentValues))
            || $this->hasCoverOnlySingleReleaseSupport($currentValues, $candidate);
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     */
    private function hasCoverOnlySingleReleaseSupport(array $currentValues, array $candidate): bool
    {
        if ($this->values->cleanString($candidate['values']['cover_url'] ?? null) === null) {
            return false;
        }

        if ($this->values->cleanString($candidate['values']['album'] ?? null) !== null) {
            return false;
        }

        return ! $this->hasDistinctAlbumContext($currentValues)
            && $this->values->cleanString($candidate['evidence']['cover_source'] ?? null) !== null;
    }

    /**
     * @param  array<string, mixed>  $currentValues
     */
    private function hasDistinctAlbumContext(array $currentValues): bool
    {
        $album = $this->values->cleanString($currentValues['album'] ?? null);
        $title = $this->values->cleanString($currentValues['title'] ?? null);

        return $album !== null
            && $title !== null
            && $this->normalizedIdentity($album) !== $this->normalizedIdentity($title);
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     */
    private function hasConflictingTitleDescriptor(array $currentValues, array $candidate): bool
    {
        $currentTitle = $this->values->cleanString($currentValues['title'] ?? null);
        $proposedTitle = $this->values->cleanString($candidate['values']['title'] ?? null);
        if ($currentTitle === null || $proposedTitle === null) {
            return false;
        }

        $currentBase = $this->titleBase($currentTitle);
        $proposedBase = $this->titleBase($proposedTitle);
        if ($currentBase === '' || $currentBase !== $proposedBase) {
            return false;
        }

        $currentDescriptors = $this->descriptorTokens($currentTitle);
        $proposedDescriptors = $this->descriptorTokens($proposedTitle);

        return $currentDescriptors !== []
            && $proposedDescriptors !== []
            && array_intersect($currentDescriptors, $proposedDescriptors) === [];
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     */
    private function addsArtistsForAmbiguousRelease(array $currentValues, array $candidate): bool
    {
        if ($this->releasePackageHasSupport($currentValues, $candidate)) {
            return false;
        }

        $currentArtists = $this->normalizedList($currentValues['artists'] ?? []);
        $proposedArtists = $this->normalizedList($candidate['values']['artists'] ?? []);

        return $currentArtists !== []
            && count($proposedArtists) > count($currentArtists)
            && array_diff($proposedArtists, $currentArtists) !== [];
    }

    private function titleBase(string $title): string
    {
        $title = preg_replace('/\([^)]*\)|\[[^]]*]/', '', $title) ?? $title;

        return $this->normalizedWords($title);
    }

    /**
     * @return list<string>
     */
    private function descriptorTokens(string $title): array
    {
        preg_match_all('/\(([^)]*)\)|\[([^]]*)]/', $title, $matches);
        $descriptor = implode(' ', array_filter([...($matches[1] ?? []), ...($matches[2] ?? [])]));
        if ($descriptor === '') {
            return [];
        }

        $tokens = preg_split('/\s+/', $this->normalizedWords($descriptor)) ?: [];
        $stopWords = ['a', 'an', 'and', 'by', 'for', 'life', 'of', 'the'];

        return array_values(array_diff(array_unique(array_filter($tokens)), $stopWords));
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
            array_map(fn (mixed $entry): ?string => $this->values->cleanString($entry), $value),
            fn (?string $entry): bool => $entry !== null,
        ));
    }

    /**
     * @return list<string>
     */
    private function normalizedList(mixed $value): array
    {
        return array_values(array_unique(array_map(
            fn (string $entry): string => $this->normalizedIdentity($entry),
            $this->values->cleanStringList($value),
        )));
    }

    private function normalizedIdentity(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value)) ?? '';
    }

    private function normalizedWords(string $value): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($value)) ?? '');
    }
}
