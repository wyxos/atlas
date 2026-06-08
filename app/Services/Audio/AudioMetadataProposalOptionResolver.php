<?php

namespace App\Services\Audio;

use App\Models\AudioMetadataProposal;

class AudioMetadataProposalOptionResolver
{
    /**
     * @param  list<string>  $fields
     * @param  array<string, mixed>  $fieldOptions
     * @return array<string, mixed>
     */
    public function selectedProposedValues(AudioMetadataProposal $proposal, array $fields, array $fieldOptions): array
    {
        $proposed = is_array($proposal->proposed_values) ? $proposal->proposed_values : [];
        $availableOptions = $this->proposalFieldOptions($proposal);

        foreach ($fields as $field) {
            $field = $this->cleanString($field);
            if ($field === null) {
                continue;
            }

            $selectedOptionId = $this->cleanString($fieldOptions[$field] ?? null);
            if ($selectedOptionId === null) {
                if (! array_key_exists($field, $proposed) && isset($availableOptions[$field])) {
                    abort(422, "No metadata option was selected for {$field}.");
                }

                continue;
            }

            $option = collect($availableOptions[$field] ?? [])
                ->first(fn (mixed $option): bool => is_array($option)
                    && $this->cleanString($option['id'] ?? null) === $selectedOptionId);

            abort_unless(is_array($option) && array_key_exists('value', $option), 422, "Invalid metadata option selected for {$field}.");

            $proposed[$field] = $option['value'];
        }

        return $proposed;
    }

    /**
     * @param  list<string>  $fields
     * @param  array<string, mixed>  $proposed
     * @return list<string>
     */
    public function normalizeApplyFields(AudioMetadataProposal $proposal, array $fields, array $proposed): array
    {
        $changes = is_array($proposal->changes) ? array_keys($proposal->changes) : [];
        $optionFields = array_keys($this->proposalFieldOptions($proposal));
        if ($fields === []) {
            return array_values(array_intersect($changes, array_keys($proposed)));
        }

        $availableFields = array_values(array_unique([...$changes, ...$optionFields, ...array_keys($proposed)]));

        return array_values(array_intersect($availableFields, $fields));
    }

    /**
     * @param  array<string, mixed>  $proposed
     * @return array<string, array{current:mixed,proposed:mixed}>
     */
    public function changesForProposal(AudioMetadataProposal $proposal, array $proposed): array
    {
        $current = is_array($proposal->current_values) ? $proposal->current_values : [];
        $changes = [];

        foreach ($proposed as $field => $value) {
            if ($this->valuesMatch($current[$field] ?? null, $value)) {
                continue;
            }

            $changes[$field] = [
                'current' => $current[$field] ?? null,
                'proposed' => $value,
            ];
        }

        return $changes;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function proposalFieldOptions(AudioMetadataProposal $proposal): array
    {
        $fieldOptions = data_get($proposal->evidence, 'field_options');
        if (! is_array($fieldOptions)) {
            return [];
        }

        return collect($fieldOptions)
            ->filter(fn (mixed $options): bool => is_array($options))
            ->map(fn (array $options): array => array_values(array_filter($options, 'is_array')))
            ->all();
    }

    private function valuesMatch(mixed $current, mixed $proposed): bool
    {
        if (is_array($current) || is_array($proposed)) {
            return $this->normalizeComparableList($current) === $this->normalizeComparableList($proposed);
        }

        if (is_numeric($current) || is_numeric($proposed)) {
            return (string) $current === (string) $proposed;
        }

        return $this->normalizeComparableString($current) === $this->normalizeComparableString($proposed);
    }

    /**
     * @return list<string>
     */
    private function normalizeComparableList(mixed $value): array
    {
        return array_values(array_map(
            fn (string $entry): string => $this->normalizeComparableString($entry),
            $this->cleanStringList($value)
        ));
    }

    private function normalizeComparableString(mixed $value): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $value))) ?? '';
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
    private function cleanStringList(mixed $value): array
    {
        if (! is_array($value)) {
            $value = [$value];
        }

        $unique = [];
        foreach ($value as $entry) {
            $clean = $this->cleanString($entry);
            if ($clean !== null) {
                $unique[$this->normalizeName($clean)] = $clean;
            }
        }

        return array_values($unique);
    }

    private function normalizeName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(trim($name))) ?? '');
    }
}
