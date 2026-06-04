<?php

namespace App\Services\Audio;

use App\Models\MetadataAlias;

class AudioMetadataCandidateAliasNormalizer
{
    public function __construct(
        private readonly AudioMetadataValueExtractor $values,
    ) {}

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     * @return array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}
     */
    public function normalize(array $currentValues, array $candidate): array
    {
        $candidate['values'] = $this->withAliases($currentValues, $candidate['values']);

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array<string, mixed>  $proposedValues
     * @return array<string, mixed>
     */
    private function withAliases(array $currentValues, array $proposedValues): array
    {
        $this->addScalarAlias($proposedValues, $currentValues, 'title', 'title_aliases');
        $this->addScalarAlias($proposedValues, $currentValues, 'album', 'album_aliases');

        $artistAliasMap = $this->artistAliasMap($currentValues, $proposedValues);
        if ($artistAliasMap !== []) {
            $proposedValues['artist_alias_map'] = $artistAliasMap;
            $proposedValues['artist_aliases'] = $this->mergeAliases(
                $proposedValues['artist_aliases'] ?? [],
                $this->flattenAliasMap($artistAliasMap),
                $proposedValues['artists'] ?? [],
            );
        }

        return $proposedValues;
    }

    /**
     * @param  array<string, mixed>  $proposedValues
     * @param  array<string, mixed>  $currentValues
     */
    private function addScalarAlias(array &$proposedValues, array $currentValues, string $field, string $aliasField): void
    {
        $current = $this->values->cleanString($currentValues[$field] ?? null);
        $proposed = $this->values->cleanString($proposedValues[$field] ?? null);
        if ($current === null || $proposed === null) {
            return;
        }

        $aliases = $this->mergeAliases($proposedValues[$aliasField] ?? [], [$current], [$proposed]);
        if ($aliases !== []) {
            $proposedValues[$aliasField] = $aliases;
        }
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array<string, mixed>  $proposedValues
     * @return array<string, list<string>>
     */
    private function artistAliasMap(array $currentValues, array $proposedValues): array
    {
        $proposedArtists = $this->values->cleanStringList($proposedValues['artists'] ?? []);
        if ($proposedArtists === []) {
            return [];
        }

        $map = $this->cleanAliasMap($proposedValues['artist_alias_map'] ?? [], $proposedArtists);
        $currentArtists = $this->values->cleanStringList($currentValues['artists'] ?? []);

        if (count($currentArtists) === count($proposedArtists)) {
            foreach ($currentArtists as $index => $currentArtist) {
                $map = $this->addMappedAlias($map, $proposedArtists[$index], $currentArtist);
            }
        } elseif (count($proposedArtists) === 1) {
            foreach ($currentArtists as $currentArtist) {
                $map = $this->addMappedAlias($map, $proposedArtists[0], $currentArtist);
            }
        }

        return $map;
    }

    /**
     * @param  list<string>  $canonicalArtists
     * @return array<string, list<string>>
     */
    private function cleanAliasMap(mixed $map, array $canonicalArtists): array
    {
        if (! is_array($map)) {
            return [];
        }

        $canonicalByKey = [];
        foreach ($canonicalArtists as $artist) {
            $canonicalByKey[MetadataAlias::normalizeValue($artist)] = $artist;
        }

        $cleanMap = [];
        foreach ($map as $artist => $aliases) {
            $artist = $this->values->cleanString($artist);
            if ($artist === null) {
                continue;
            }

            $artistKey = MetadataAlias::normalizeValue($artist);
            if (! isset($canonicalByKey[$artistKey])) {
                continue;
            }

            $artist = $canonicalByKey[$artistKey];
            $cleanMap[$artist] = $this->mergeAliases($cleanMap[$artist] ?? [], $aliases, [$artist]);
        }

        return array_filter($cleanMap, fn (array $aliases): bool => $aliases !== []);
    }

    /**
     * @param  array<string, list<string>>  $map
     * @return array<string, list<string>>
     */
    private function addMappedAlias(array $map, string $artist, string $alias): array
    {
        $aliases = $this->mergeAliases($map[$artist] ?? [], [$alias], [$artist]);
        if ($aliases !== []) {
            $map[$artist] = $aliases;
        }

        return $map;
    }

    /**
     * @param  array<string, list<string>>  $map
     * @return list<string>
     */
    private function flattenAliasMap(array $map): array
    {
        return $this->values->cleanStringList(collect($map)->flatten()->all());
    }

    /**
     * @return list<string>
     */
    private function mergeAliases(mixed $existing, mixed $aliases, mixed $canonicalValues): array
    {
        $canonical = array_fill_keys(array_map(
            fn (string $value): string => MetadataAlias::normalizeValue($value),
            $this->values->cleanStringList($canonicalValues),
        ), true);
        $unique = [];

        foreach ([...$this->values->cleanStringList($existing), ...$this->values->cleanStringList($aliases)] as $alias) {
            $normalized = MetadataAlias::normalizeValue($alias);
            if ($normalized === '' || isset($canonical[$normalized])) {
                continue;
            }

            $unique[$normalized] = $alias;
        }

        return array_values($unique);
    }
}
