<?php

namespace App\Services\Audio;

use App\Models\MetadataAlias;

class MusicBrainzArtistAliasExtractor
{
    public function __construct(
        private readonly AudioMetadataValueExtractor $values,
    ) {}

    /**
     * @param  array<string, mixed>  $recording
     * @return array<string, list<string>>
     */
    public function mapForRecording(array $recording): array
    {
        $artistCredits = data_get($recording, 'artist-credit', []);
        if (! is_array($artistCredits)) {
            return [];
        }

        $map = [];
        foreach ($artistCredits as $credit) {
            if (! is_array($credit)) {
                continue;
            }

            $artist = $this->values->cleanString(data_get($credit, 'artist.name') ?? $credit['name'] ?? null);
            if ($artist === null) {
                continue;
            }

            $aliases = $this->aliasesForCredit($credit, $artist);
            if ($aliases !== []) {
                $map[$artist] = $aliases;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $credit
     * @return list<string>
     */
    private function aliasesForCredit(array $credit, string $artist): array
    {
        $aliases = [];
        $this->pushAlias($aliases, $credit['name'] ?? null, $artist);

        foreach ($this->sortNameAliases(data_get($credit, 'artist.sort-name')) as $alias) {
            $this->pushAlias($aliases, $alias, $artist);
        }

        foreach (data_get($credit, 'artist.aliases', []) as $alias) {
            $this->pushAlias($aliases, is_array($alias) ? ($alias['name'] ?? null) : $alias, $artist);
        }

        return array_values($aliases);
    }

    /**
     * @return list<string>
     */
    private function sortNameAliases(mixed $sortName): array
    {
        $sortName = $this->values->cleanString($sortName);
        if ($sortName === null) {
            return [];
        }

        if (str_contains($sortName, ',')) {
            $parts = array_map('trim', explode(',', $sortName, 2));
            if (($parts[0] ?? '') !== '' && ($parts[1] ?? '') !== '') {
                return [$parts[0].' '.$parts[1], $parts[1].' '.$parts[0]];
            }
        }

        return [$sortName];
    }

    /**
     * @param  array<string, string>  $aliases
     */
    private function pushAlias(array &$aliases, mixed $alias, string $artist): void
    {
        $alias = $this->values->cleanString($alias);
        if ($alias === null) {
            return;
        }

        $normalized = MetadataAlias::normalizeValue($alias);
        if ($normalized === '' || $normalized === MetadataAlias::normalizeValue($artist)) {
            return;
        }

        $aliases[$normalized] = $alias;
    }
}
