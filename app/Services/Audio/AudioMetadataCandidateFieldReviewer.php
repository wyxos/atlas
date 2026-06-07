<?php

namespace App\Services\Audio;

use App\Models\File;
use RuntimeException;

class AudioMetadataCandidateFieldReviewer
{
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
            if ($review === null) {
                throw new RuntimeException('AI metadata field review returned no usable response.');
            }

            $candidate['evidence']['field_review'] = $review;
            $candidate['values'] = $this->valuesAllowedByReview($candidate['values'], $review);

            return $candidate['values'] === [] ? null : $candidate;
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

        if (($candidate['evidence']['ai_review']['verdict'] ?? null) === 'accept'
            && ($candidate['evidence']['ai_review']['source_identity_supported'] ?? false) === true) {
            return false;
        }

        if (($candidate['evidence']['release_consistency_review']['verdict'] ?? null) === 'accept') {
            return false;
        }

        return str_starts_with((string) ($candidate['provider'] ?? ''), 'acoustid_musicbrainz')
            || ($candidate['provider'] ?? null) === 'discogs_release';
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
            return [];
        }

        return $values;
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

        if (($candidate['evidence']['identity_support'] ?? null) === 'strong_fingerprint_release') {
            return true;
        }

        return $this->hasCoverOnlyReleaseSupport($candidate);
    }

    /**
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     */
    private function hasCoverOnlyReleaseSupport(array $candidate): bool
    {
        return ($candidate['evidence']['identity_support'] ?? null) === 'release_with_cover'
            && $this->values->cleanString($candidate['values']['cover_url'] ?? null) !== null
            && $this->values->cleanString($candidate['values']['album'] ?? null) === null;
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
}
