<?php

namespace App\Services\Audio;

use App\Models\File;
use Throwable;

class AudioMetadataCandidateAggregator
{
    private const REVIEW_FIELDS = [
        'title',
        'artists',
        'album',
        'duration_seconds',
        'cover_url',
        'spotify_uri',
        'track_number',
        'disc_number',
        'release_label',
        'catalog_number',
        'barcode',
        'release_date',
        'release_country',
        'isrc',
        'musicbrainz_recording_id',
        'musicbrainz_release_id',
        'discogs_release_id',
    ];

    private const RELEASE_LEVEL_FIELDS = [
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
        private readonly AudioMetadataCandidateFieldReviewer $fieldReviewer,
        private readonly AudioMetadataValueExtractor $values,
    ) {}

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  list<array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}>  $candidates
     * @param  callable(array<string, mixed>): array<string, array{current:mixed,proposed:mixed}>  $changesFor
     * @return array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}|null
     */
    public function aggregate(File $file, array $currentValues, array $candidates, callable $changesFor): ?array
    {
        $fieldOptions = [];
        $recommendedValues = [];
        $recommendedProvider = null;
        $recommendedProviders = [];
        $recommendedConfidence = 0;
        $primaryCandidate = null;
        $hasSourceRecommendation = false;
        $hasConclusiveSourceRecommendation = false;
        $candidateSummaries = [];

        foreach ($candidates as $index => $candidate) {
            $candidateValues = $this->reviewableValues($candidate['values'] ?? []);
            if ($candidateValues === []) {
                continue;
            }

            if ($hasConclusiveSourceRecommendation && $this->requiresAiFieldReview($candidate)) {
                continue;
            }

            $changes = $changesFor($candidateValues);
            $reviewedCandidate = null;
            $reviewError = null;

            try {
                $reviewedCandidate = $this->fieldReviewer->review($file, $currentValues, [
                    ...$candidate,
                    'values' => $candidateValues,
                ], $changes);
            } catch (Throwable $throwable) {
                $reviewError = $throwable->getMessage();
            }

            $review = $this->fieldReview($reviewedCandidate);
            $recommendedFields = $this->recommendedFields($candidate, $reviewedCandidate, $review, $reviewError);
            if ($hasSourceRecommendation && $this->isLocalCandidate($candidate)) {
                $recommendedFields = [];
            }

            $optionFields = $this->optionFields($candidateValues, $changes);

            foreach ($recommendedFields as $field) {
                if (! array_key_exists($field, $candidateValues) || array_key_exists($field, $recommendedValues)) {
                    continue;
                }

                $recommendedValues[$field] = $candidateValues[$field];
                $recommendedProvider ??= (string) $candidate['provider'];
                $recommendedProviders[(string) $candidate['provider']] = true;
                $recommendedConfidence = max($recommendedConfidence, (int) $candidate['confidence']);
                $primaryCandidate ??= $reviewedCandidate;
            }

            if ($this->isSourceCandidate($candidate) && $recommendedFields !== []) {
                $hasSourceRecommendation = true;
                $hasConclusiveSourceRecommendation = $hasConclusiveSourceRecommendation
                    || $this->hasReleaseLevelRecommendation($recommendedFields);
            }

            foreach ($optionFields as $field) {
                if (! array_key_exists($field, $candidateValues)) {
                    continue;
                }

                $recommended = in_array($field, $recommendedFields, true);
                $option = $this->fieldOption($candidate, $field, $candidateValues[$field], $index, $recommended, $review, $reviewError);
                if ($this->hasEquivalentOption($fieldOptions[$field] ?? [], $option)) {
                    continue;
                }

                $fieldOptions[$field][] = $option;
            }

            $candidateSummaries[] = $this->candidateSummary($candidate, $review, $reviewError, $recommendedFields);
        }

        $fieldOptions = array_filter($fieldOptions, fn (array $options): bool => $options !== []);
        if ($recommendedValues === [] && $fieldOptions === []) {
            return null;
        }

        $confidence = $recommendedConfidence > 0
            ? $recommendedConfidence
            : max(array_map(fn (array $candidate): int => (int) $candidate['confidence'], $candidates) ?: [0]);
        $fallbackCandidate = $candidates[0] ?? null;
        $evidenceCandidate = $primaryCandidate ?? $fallbackCandidate;
        $baseEvidence = is_array($evidenceCandidate['evidence'] ?? null) ? $evidenceCandidate['evidence'] : [];
        $source = $this->values->cleanString($baseEvidence['source'] ?? null)
            ?? (count($candidateSummaries) > 1 ? 'multi_source_metadata_review' : ($candidateSummaries[0]['source'] ?? 'metadata_review'));

        return [
            'provider' => count($recommendedProviders) > 1
                ? 'multi_source_review'
                : ($recommendedProvider ?? (count($candidateSummaries) > 1 ? 'multi_source_review' : (string) ($fallbackCandidate['provider'] ?? 'metadata_review'))),
            'confidence' => max(0, min(96, $confidence)),
            'values' => $recommendedValues,
            'evidence' => [
                ...$baseEvidence,
                'source' => $source,
                'candidate_count' => count($candidateSummaries),
                'field_options' => $fieldOptions,
                'provider_candidates' => $candidateSummaries,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function reviewableValues(array $values): array
    {
        return array_intersect_key($values, array_flip(self::REVIEW_FIELDS));
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, array{current:mixed,proposed:mixed}>  $changes
     * @return list<string>
     */
    private function optionFields(array $values, array $changes): array
    {
        $fields = array_keys($changes);

        if (array_key_exists('album', $values) && $this->hasReleaseContext($values)) {
            $fields[] = 'album';
        }

        return array_values(array_intersect(array_unique($fields), self::REVIEW_FIELDS));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function hasReleaseContext(array $values): bool
    {
        return $this->values->cleanString($values['cover_url'] ?? null) !== null
            || $this->values->cleanString($values['musicbrainz_release_id'] ?? null) !== null
            || $this->values->cleanString($values['discogs_release_id'] ?? null) !== null
            || $this->values->cleanString($values['release_label'] ?? null) !== null
            || $this->values->cleanString($values['catalog_number'] ?? null) !== null;
    }

    /**
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     */
    private function isLocalCandidate(array $candidate): bool
    {
        return ($candidate['provider'] ?? null) === 'local';
    }

    /**
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     */
    private function isSourceCandidate(array $candidate): bool
    {
        return ! $this->isLocalCandidate($candidate);
    }

    /**
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     */
    private function requiresAiFieldReview(array $candidate): bool
    {
        return str_starts_with((string) ($candidate['provider'] ?? ''), 'acoustid_musicbrainz')
            || ($candidate['provider'] ?? null) === 'discogs_release';
    }

    /**
     * @param  list<string>  $fields
     */
    private function hasReleaseLevelRecommendation(array $fields): bool
    {
        return array_intersect($fields, self::RELEASE_LEVEL_FIELDS) !== [];
    }

    /**
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}|null  $candidate
     * @return array<string, mixed>|null
     */
    private function fieldReview(?array $candidate): ?array
    {
        $review = $candidate['evidence']['field_review'] ?? null;

        return is_array($review) ? $review : null;
    }

    /**
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $originalCandidate
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}|null  $reviewedCandidate
     * @param  array<string, mixed>|null  $review
     * @return list<string>
     */
    private function recommendedFields(array $originalCandidate, ?array $reviewedCandidate, ?array $review, ?string $reviewError): array
    {
        if ($reviewError !== null || $reviewedCandidate === null || ($originalCandidate['evidence']['manual_review_required'] ?? false) === true) {
            return [];
        }

        $fields = array_keys($this->reviewableValues($reviewedCandidate['values'] ?? []));
        if ($review === null) {
            return $fields;
        }

        return match ($review['verdict'] ?? null) {
            'accept' => $fields,
            'ambiguous' => array_values(array_diff($fields, self::RELEASE_LEVEL_FIELDS)),
            default => [],
        };
    }

    /**
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     * @param  array<string, mixed>|null  $review
     * @return array{id:string,provider:string,confidence:int,value:mixed,recommended:bool,reason:string|null,review_verdict:string|null}
     */
    private function fieldOption(array $candidate, string $field, mixed $value, int $index, bool $recommended, ?array $review, ?string $reviewError): array
    {
        return [
            'id' => $this->optionId($candidate, $field, $value, $index),
            'provider' => (string) $candidate['provider'],
            'confidence' => (int) $candidate['confidence'],
            'value' => $value,
            'recommended' => $recommended,
            'reason' => $reviewError ?? $this->values->cleanString($review['reason'] ?? null),
            'review_verdict' => $this->values->cleanString($review['verdict'] ?? null),
        ];
    }

    /**
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     */
    private function optionId(array $candidate, string $field, mixed $value, int $index): string
    {
        $releaseId = $this->values->cleanString($candidate['evidence']['discogs_release_id'] ?? $candidate['evidence']['musicbrainz_release_id'] ?? null);
        $source = $this->values->cleanString($candidate['evidence']['source'] ?? null);
        $payload = json_encode([
            $index,
            $candidate['provider'],
            $source,
            $releaseId,
            $field,
            $value,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return substr(sha1((string) $payload), 0, 16);
    }

    /**
     * @param  list<array{id:string,provider:string,confidence:int,value:mixed,recommended:bool,reason:string|null,review_verdict:string|null}>  $options
     * @param  array{id:string,provider:string,confidence:int,value:mixed,recommended:bool,reason:string|null,review_verdict:string|null}  $option
     */
    private function hasEquivalentOption(array $options, array $option): bool
    {
        foreach ($options as $existing) {
            if (($existing['provider'] ?? null) === $option['provider']
                && $this->comparableValue($existing['value'] ?? null) === $this->comparableValue($option['value'])) {
                return true;
            }
        }

        return false;
    }

    private function comparableValue(mixed $value): string
    {
        if (is_array($value)) {
            return implode('|', array_map(fn (mixed $entry): string => $this->comparableValue($entry), $value));
        }

        return mb_strtolower(trim((string) $value));
    }

    /**
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     * @param  array<string, mixed>|null  $review
     * @param  list<string>  $recommendedFields
     * @return array<string, mixed>
     */
    private function candidateSummary(array $candidate, ?array $review, ?string $reviewError, array $recommendedFields): array
    {
        return [
            'provider' => (string) $candidate['provider'],
            'confidence' => (int) $candidate['confidence'],
            'source' => $this->values->cleanString($candidate['evidence']['source'] ?? null),
            'discogs_release_id' => $this->values->cleanString($candidate['evidence']['discogs_release_id'] ?? null),
            'musicbrainz_release_id' => $this->values->cleanString($candidate['evidence']['musicbrainz_release_id'] ?? null),
            'matched_existing_fields' => $this->values->cleanStringList($candidate['evidence']['matched_existing_fields'] ?? []),
            'review_verdict' => $this->values->cleanString($review['verdict'] ?? null),
            'review_error' => $reviewError,
            'recommended_fields' => $recommendedFields,
        ];
    }
}
