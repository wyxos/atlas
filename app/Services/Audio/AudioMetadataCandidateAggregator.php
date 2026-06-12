<?php

namespace App\Services\Audio;

use App\Models\File;

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

    public function __construct(
        private readonly AudioMetadataCandidateOptionMerger $optionMerger,
        private readonly AudioMetadataCandidateSourceLinkResolver $sourceLinks,
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
        $candidateSummaries = [];

        foreach ($candidates as $index => $candidate) {
            $candidateValues = $this->reviewableValues($candidate['values'] ?? []);
            if ($candidateValues === []) {
                continue;
            }

            $changes = $changesFor($candidateValues);
            $optionFields = $this->optionFields($candidateValues, $changes);

            foreach ($optionFields as $field) {
                if (! array_key_exists($field, $candidateValues)) {
                    continue;
                }

                $option = $this->fieldOption($candidate, $field, $candidateValues[$field], $index);
                $fieldOptions[$field] = $this->optionMerger->add($fieldOptions[$field] ?? [], $option);
            }

            $candidateSummaries[] = $this->candidateSummary($candidate);
        }

        $fieldOptions = array_filter($fieldOptions, fn (array $options): bool => $options !== []);
        if ($fieldOptions === []) {
            return null;
        }

        $confidence = max(array_map(fn (array $candidate): int => (int) $candidate['confidence'], $candidates) ?: [0]);
        $fallbackCandidate = $candidates[0] ?? null;
        $baseEvidence = is_array($fallbackCandidate['evidence'] ?? null) ? $fallbackCandidate['evidence'] : [];
        $source = $this->values->cleanString($baseEvidence['source'] ?? null)
            ?? (count($candidateSummaries) > 1 ? 'multi_source_metadata_review' : ($candidateSummaries[0]['source'] ?? 'metadata_review'));

        return [
            'provider' => count($candidateSummaries) > 1
                ? 'multi_source_review'
                : (string) ($fallbackCandidate['provider'] ?? 'metadata_review'),
            'confidence' => max(0, min(96, $confidence)),
            'values' => [],
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
     * @return array{id:string,provider:string,confidence:int,value:mixed,recommended:bool,reason:string|null,reason_scope:string|null,review_verdict:string|null,source_label:string|null,source_url:string|null}
     */
    private function fieldOption(array $candidate, string $field, mixed $value, int $index): array
    {
        $sourceLink = $this->sourceLinks->forCandidate($candidate);

        return [
            'id' => $this->optionId($candidate, $field, $value, $index),
            'provider' => (string) $candidate['provider'],
            'confidence' => (int) $candidate['confidence'],
            'value' => $value,
            'recommended' => false,
            'reason' => null,
            'reason_scope' => null,
            'review_verdict' => null,
            'source_label' => $sourceLink['label'],
            'source_url' => $sourceLink['url'],
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
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     * @return array<string, mixed>
     */
    private function candidateSummary(array $candidate): array
    {
        $sourceLink = $this->sourceLinks->forCandidate($candidate);

        return [
            'provider' => (string) $candidate['provider'],
            'confidence' => (int) $candidate['confidence'],
            'source' => $this->values->cleanString($candidate['evidence']['source'] ?? null),
            'source_label' => $sourceLink['label'],
            'source_url' => $sourceLink['url'],
            'discogs_release_id' => $this->values->cleanString($candidate['evidence']['discogs_release_id'] ?? null),
            'musicbrainz_release_id' => $this->values->cleanString($candidate['evidence']['musicbrainz_release_id'] ?? null),
            'matched_existing_fields' => $this->values->cleanStringList($candidate['evidence']['matched_existing_fields'] ?? []),
            'review_verdict' => null,
            'review_error' => null,
            'recommended_fields' => [],
        ];
    }
}
