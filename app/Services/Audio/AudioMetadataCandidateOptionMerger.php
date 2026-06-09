<?php

namespace App\Services\Audio;

class AudioMetadataCandidateOptionMerger
{
    private const CONSENSUS_OPTION_FIELDS = [
        'track_number',
        'disc_number',
    ];

    public function __construct(
        private readonly AudioMetadataValueExtractor $values,
    ) {}

    /**
     * @param  list<array{id:string,provider:string,confidence:int,value:mixed,recommended:bool,reason:string|null,reason_scope:string|null,review_verdict:string|null,source_label:string|null,source_url:string|null}>  $options
     * @param  array{id:string,provider:string,confidence:int,value:mixed,recommended:bool,reason:string|null,reason_scope:string|null,review_verdict:string|null,source_label:string|null,source_url:string|null}  $option
     * @return list<array{id:string,provider:string,confidence:int,value:mixed,recommended:bool,reason:string|null,reason_scope:string|null,review_verdict:string|null,source_label:string|null,source_url:string|null}>
     */
    public function add(array $options, array $option): array
    {
        $option['support_count'] = max(1, (int) ($option['support_count'] ?? 1));

        foreach ($options as $index => $existing) {
            if ($this->comparableValue($existing['value'] ?? null) !== $this->comparableValue($option['value'])) {
                continue;
            }

            $options[$index] = $this->merge($existing, $option);

            return $options;
        }

        $options[] = $option;

        return $options;
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array<string, list<array{id:string,provider:string,confidence:int,value:mixed,recommended:bool,reason:string|null,reason_scope:string|null,review_verdict:string|null,source_label:string|null,source_url:string|null}>>  $fieldOptions
     * @param  array<string, mixed>  $recommendedValues
     * @param  array<string, bool>  $recommendedProviders
     */
    public function promoteConsensusTrackFields(
        array $currentValues,
        array &$fieldOptions,
        array &$recommendedValues,
        ?string &$recommendedProvider,
        array &$recommendedProviders,
        int &$recommendedConfidence,
    ): void {
        foreach (self::CONSENSUS_OPTION_FIELDS as $field) {
            if (array_key_exists($field, $recommendedValues)
                || $this->values->cleanString($currentValues[$field] ?? null) !== null
                || ! $this->hasConsensusSourceOption($fieldOptions[$field] ?? [])) {
                continue;
            }

            $bestIndex = $this->bestOptionIndex($fieldOptions[$field]);
            $fieldOptions[$field][$bestIndex]['recommended'] = true;
            $fieldOptions[$field][$bestIndex]['reason'] ??= 'All available metadata sources agree on this missing field.';
            $fieldOptions[$field][$bestIndex]['reason_scope'] ??= 'field';

            $option = $fieldOptions[$field][$bestIndex];
            $recommendedValues[$field] = $option['value'];
            $recommendedProvider ??= (string) $option['provider'];
            $recommendedProviders[(string) $option['provider']] = true;
            $recommendedConfidence = max($recommendedConfidence, (int) $option['confidence']);
        }
    }

    /**
     * @param  array{id:string,provider:string,confidence:int,value:mixed,recommended:bool,reason:string|null,reason_scope:string|null,review_verdict:string|null,source_label:string|null,source_url:string|null}  $existing
     * @param  array{id:string,provider:string,confidence:int,value:mixed,recommended:bool,reason:string|null,reason_scope:string|null,review_verdict:string|null,source_label:string|null,source_url:string|null}  $option
     * @return array{id:string,provider:string,confidence:int,value:mixed,recommended:bool,reason:string|null,reason_scope:string|null,review_verdict:string|null,source_label:string|null,source_url:string|null}
     */
    private function merge(array $existing, array $option): array
    {
        $preferOption = $option['recommended'] && ! $existing['recommended'];

        return [
            ...$existing,
            'confidence' => max((int) $existing['confidence'], (int) $option['confidence']),
            'recommended' => $existing['recommended'] || $option['recommended'],
            'support_count' => max(1, (int) ($existing['support_count'] ?? 1)) + max(1, (int) ($option['support_count'] ?? 1)),
            'reason' => $preferOption ? $option['reason'] : ($existing['reason'] ?? $option['reason']),
            'reason_scope' => $preferOption ? $option['reason_scope'] : ($existing['reason_scope'] ?? $option['reason_scope']),
            'review_verdict' => $preferOption ? $option['review_verdict'] : ($existing['review_verdict'] ?? $option['review_verdict']),
            'source_label' => $preferOption ? $option['source_label'] : ($existing['source_label'] ?? $option['source_label']),
            'source_url' => $preferOption ? $option['source_url'] : ($existing['source_url'] ?? $option['source_url']),
        ];
    }

    /**
     * @param  list<array{id:string,provider:string,confidence:int,value:mixed,recommended:bool,reason:string|null,reason_scope:string|null,review_verdict:string|null,source_label:string|null,source_url:string|null}>  $options
     */
    private function hasConsensusSourceOption(array $options): bool
    {
        if ($options === [] || $this->hasUnsafeReview($options)) {
            return false;
        }

        $values = array_unique(array_map(fn (array $option): string => $this->comparableValue($option['value']), $options));
        $supportCount = array_sum(array_map(fn (array $option): int => max(1, (int) ($option['support_count'] ?? 1)), $options));

        return count($values) === 1
            && $supportCount > 1
            && collect($options)->contains(fn (array $option): bool => ($option['provider'] ?? null) !== 'local');
    }

    /**
     * @param  list<array{id:string,provider:string,confidence:int,value:mixed,recommended:bool,reason:string|null,reason_scope:string|null,review_verdict:string|null,source_label:string|null,source_url:string|null}>  $options
     */
    private function hasUnsafeReview(array $options): bool
    {
        return collect($options)->contains(fn (array $option): bool => in_array($option['review_verdict'] ?? null, ['ambiguous', 'reject'], true));
    }

    /**
     * @param  list<array{id:string,provider:string,confidence:int,value:mixed,recommended:bool,reason:string|null,reason_scope:string|null,review_verdict:string|null,source_label:string|null,source_url:string|null}>  $options
     */
    private function bestOptionIndex(array $options): int
    {
        $bestIndex = 0;
        $bestConfidence = -1;

        foreach ($options as $index => $option) {
            if ((int) $option['confidence'] <= $bestConfidence) {
                continue;
            }

            $bestIndex = $index;
            $bestConfidence = (int) $option['confidence'];
        }

        return $bestIndex;
    }

    private function comparableValue(mixed $value): string
    {
        if (is_array($value)) {
            return implode('|', array_map(fn (mixed $entry): string => $this->comparableValue($entry), $value));
        }

        return mb_strtolower(trim((string) $value));
    }
}
