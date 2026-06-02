<?php

namespace App\Services\Audio;

use App\Models\File;

class AudioMetadataValueExtractor
{
    /**
     * @return array<string, mixed>
     */
    public function metadataPayload(File $file): array
    {
        $payload = $file->metadata?->payload;

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function durationSeconds(File $file, array $payload): ?int
    {
        $seconds = data_get($payload, 'duration_seconds')
            ?? data_get($payload, 'duration')
            ?? data_get($payload, 'probe.format.duration')
            ?? data_get($file->listing_metadata, 'duration_seconds');

        if (! is_numeric($seconds)) {
            return null;
        }

        $seconds = (int) round((float) $seconds);

        return $seconds > 0 ? $seconds : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    public function firstStringForKeys(array $payload, array $keys): ?string
    {
        foreach ($this->valuesForKeys($payload, $keys) as $value) {
            foreach ($this->flattenStringValues($value) as $entry) {
                $clean = $this->cleanString($entry);
                if ($clean !== null) {
                    return $clean;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function extractNames(array $payload, array $keys): array
    {
        $names = [];
        foreach ($this->valuesForKeys($payload, $keys) as $value) {
            $names = [...$names, ...$this->flattenStringValues($value)];
        }

        return $this->uniqueNames($names);
    }

    /**
     * @return array{artist:string,title:string}|null
     */
    public function filenameCandidate(string $filename): ?array
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        if (! is_string($name) || ! str_contains($name, ' - ')) {
            return null;
        }

        [$artist, $title] = array_map('trim', explode(' - ', $name, 2));

        return $artist !== '' && $title !== '' ? ['artist' => $artist, 'title' => $title] : null;
    }

    public function cleanString(mixed $value): ?string
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
    public function cleanStringList(mixed $value): array
    {
        return $this->uniqueNames($this->flattenStringValues($value));
    }

    public function positiveInteger(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) round((float) $value);

        return $value > 0 ? $value : null;
    }

    public function normalizeName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(trim($name))) ?? '');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     * @return list<mixed>
     */
    private function valuesForKeys(array $payload, array $keys): array
    {
        $normalizedKeys = array_map(fn (string $key): string => $this->normalizeKey($key), $keys);
        $values = [];

        foreach ($this->metadataSources($payload) as $source) {
            foreach ($source as $key => $value) {
                if (in_array($this->normalizeKey((string) $key), $normalizedKeys, true)) {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function metadataSources(array $payload): array
    {
        $sources = [$payload];
        $formatTags = data_get($payload, 'probe.format.tags');
        if (is_array($formatTags)) {
            $sources[] = $formatTags;
        }

        return $sources;
    }

    /**
     * @return list<string>
     */
    private function flattenStringValues(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value) || is_numeric($value)) {
            $parts = preg_split('/\s*[;,|]\s*/', (string) $value) ?: [];

            return array_values(array_filter($parts, fn (string $part): bool => $this->cleanString($part) !== null));
        }

        if (! is_array($value)) {
            return [];
        }

        $flattened = [];
        foreach ($value as $item) {
            $flattened = [...$flattened, ...$this->flattenStringValues($item)];
        }

        return $flattened;
    }

    /**
     * @return list<string>
     */
    private function uniqueNames(array $names): array
    {
        $unique = [];

        foreach ($names as $name) {
            $clean = $this->cleanString($name);
            if ($clean !== null) {
                $unique[$this->normalizeName($clean)] = $clean;
            }
        }

        return array_values($unique);
    }

    private function normalizeKey(string $key): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($key)) ?? '';
    }
}
