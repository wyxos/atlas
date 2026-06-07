<?php

namespace App\Services\Audio;

class AudioMetadataDiscogsSearchQueryExpander
{
    private const MAX_QUERIES = 18;

    public function __construct(
        private readonly AudioMetadataValueExtractor $values,
    ) {}

    /**
     * @param  list<array{release_title:string,artist:string,reason:string|null}>  $queries
     * @return list<array{release_title:string,artist:string,reason:string|null}>
     */
    public function expand(array $queries): array
    {
        $expanded = [];

        foreach ($queries as $query) {
            $releaseTitle = $this->values->cleanString($query['release_title'] ?? null);
            $artist = $this->values->cleanString($query['artist'] ?? null);
            if ($releaseTitle === null || $artist === null) {
                continue;
            }

            foreach ($this->releaseTitleVariants($releaseTitle) as $titleVariant) {
                foreach ($this->artistVariants($artist) as $artistVariant) {
                    $key = mb_strtolower($titleVariant).'|'.mb_strtolower($artistVariant);
                    $expanded[$key] ??= [
                        'release_title' => $titleVariant,
                        'artist' => $artistVariant,
                        'reason' => $this->reason($query['reason'] ?? null, $releaseTitle, $artist, $titleVariant, $artistVariant),
                    ];

                    if (count($expanded) >= self::MAX_QUERIES) {
                        return array_values($expanded);
                    }
                }
            }
        }

        return array_values($expanded);
    }

    /**
     * @return list<string>
     */
    private function releaseTitleVariants(string $releaseTitle): array
    {
        $withoutSquareBrackets = preg_replace('/\s*\[[^]]+]\s*/', ' ', $releaseTitle) ?? $releaseTitle;
        $withoutAnyBrackets = preg_replace('/\s*(?:\([^)]*\)|\[[^]]*])\s*/', ' ', $releaseTitle) ?? $releaseTitle;
        $squareAsParentheses = preg_replace('/\[([^]]+)]/', '($1)', $releaseTitle) ?? $releaseTitle;
        $withoutTrailingMixDescriptor = preg_replace('/\s+\b(?:remix|mix|edit|version)\b$/i', '', $withoutAnyBrackets) ?? $withoutAnyBrackets;

        return $this->uniqueStrings([
            $releaseTitle,
            $squareAsParentheses,
            $withoutSquareBrackets,
            $withoutAnyBrackets,
            $withoutTrailingMixDescriptor,
            preg_replace('/\b(?:tv|television|anime|animation|animated)\b/i', ' ', $releaseTitle) ?? $releaseTitle,
        ]);
    }

    /**
     * @return list<string>
     */
    private function artistVariants(string $artist): array
    {
        if (preg_match('/[a-z]/i', $artist) !== 1) {
            return [$artist];
        }

        $splitArtists = $this->splitArtists($artist);
        $variants = [
            $artist,
            ...$splitArtists,
        ];

        if (count($splitArtists) > 1) {
            $variants[] = implode(' vs ', $splitArtists);
            $variants[] = implode(' ', $splitArtists);
        }

        return $this->uniqueStrings(collect($variants)
            ->flatMap(fn (string $variant): array => [
                $variant,
                preg_replace('/n(?=[mbp])/i', 'm', $variant) ?? $variant,
                preg_replace('/m(?=[mbp])/i', 'n', $variant) ?? $variant,
            ])
            ->all());
    }

    /**
     * @return list<string>
     */
    private function splitArtists(string $artist): array
    {
        $parts = preg_split('/\s*(?:\/|,|;|\+|&|\bvs\.?\b|\bfeat(?:uring)?\.?\b)\s*/i', $artist) ?: [];

        return $this->uniqueStrings($parts);
    }

    /**
     * @param  list<string|null>  $values
     * @return list<string>
     */
    private function uniqueStrings(array $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            $clean = $this->values->cleanString($value);
            if ($clean !== null) {
                $unique[mb_strtolower($clean)] = $clean;
            }
        }

        return array_values($unique);
    }

    private function reason(?string $reason, string $releaseTitle, string $artist, string $titleVariant, string $artistVariant): ?string
    {
        if ($releaseTitle === $titleVariant && $artist === $artistVariant) {
            return $this->values->cleanString($reason);
        }

        return 'Expanded from AI Discogs search query.';
    }
}
