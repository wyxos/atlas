<?php

namespace App\Services\Audio;

class AudioMetadataVgmdbSearchQueryExpander
{
    public function __construct(
        private readonly AudioMetadataValueExtractor $values,
    ) {}

    /**
     * @param  list<string>  $seeds
     * @return list<string>
     */
    public function expand(array $seeds, int $limit = 10): array
    {
        $queries = [];
        foreach ($seeds as $seed) {
            $queries = [
                ...$queries,
                ...$this->variants($seed),
            ];
        }

        return $this->uniqueStrings($queries, $limit);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    public function matchIdentities(array $values): array
    {
        $identities = [];
        foreach ($this->values->cleanStringList($values) as $value) {
            foreach ($this->variants($value) as $variant) {
                $identity = $this->normalizedIdentity($variant);
                if ($identity !== '') {
                    $identities[$identity] = $identity;
                }
            }
        }

        return array_values($identities);
    }

    /**
     * @return list<string>
     */
    private function variants(string $value): array
    {
        $clean = $this->values->cleanString($value);
        if ($clean === null) {
            return [];
        }

        $variants = [$clean];

        $withoutTrackNumber = $this->withoutLeadingTrackNumber($clean);
        if ($withoutTrackNumber !== null) {
            $variants[] = $withoutTrackNumber;
        }

        $withoutBracketedText = $this->withoutBracketedText($clean);
        if ($withoutBracketedText !== null) {
            $variants[] = $withoutBracketedText;
            $withoutBracketedTrackNumber = $this->withoutLeadingTrackNumber($withoutBracketedText);
            if ($withoutBracketedTrackNumber !== null) {
                $variants[] = $withoutBracketedTrackNumber;
            }
        }

        $withoutSoundtrackTerms = $this->withoutSoundtrackTerms($clean);
        if ($withoutSoundtrackTerms !== null) {
            $variants[] = $withoutSoundtrackTerms;
        }

        foreach ($this->dashSuffixes($clean) as $suffix) {
            $variants[] = $suffix;
            $suffixWithoutSoundtrackTerms = $this->withoutSoundtrackTerms($suffix);
            if ($suffixWithoutSoundtrackTerms !== null) {
                $variants[] = $suffixWithoutSoundtrackTerms;
            }
        }

        return $this->uniqueStrings($variants);
    }

    private function withoutLeadingTrackNumber(string $value): ?string
    {
        return $this->values->cleanString(preg_replace('/^\s*(?:\d{1,3}|[a-z]\d{1,3})[\s._-]+/iu', '', $value) ?? $value);
    }

    private function withoutBracketedText(string $value): ?string
    {
        return $this->values->cleanString(preg_replace('/\s*(?:\([^)]*\)|\[[^]]*])\s*/u', ' ', $value) ?? $value);
    }

    private function withoutSoundtrackTerms(string $value): ?string
    {
        return $this->values->cleanString(preg_replace('/\b(?:original\s+game\s+soundtrack|original\s+soundtrack|game\s+soundtrack|soundtrack|gamerip|game\s+rip|ost|disc\s*\d+|cd\s*\d+)\b/iu', ' ', $value) ?? $value);
    }

    /**
     * @return list<string>
     */
    private function dashSuffixes(string $value): array
    {
        $parts = preg_split('/\s+[-–—]\s+/u', $value) ?: [];
        if (count($parts) < 2) {
            return [];
        }

        return $this->uniqueStrings(array_slice($parts, 1));
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function uniqueStrings(array $values, ?int $limit = null): array
    {
        $unique = [];
        foreach ($values as $value) {
            $clean = $this->values->cleanString($value);
            if ($clean !== null) {
                $unique[$this->normalizedStringKey($clean)] = $clean;
            }
        }

        $strings = array_values($unique);

        return $limit === null ? $strings : array_slice($strings, 0, $limit);
    }

    private function normalizedIdentity(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value)) ?? '';
    }

    private function normalizedStringKey(string $value): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($value))) ?? '';
    }
}
