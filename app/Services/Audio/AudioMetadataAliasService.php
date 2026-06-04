<?php

namespace App\Services\Audio;

use App\Models\Album;
use App\Models\Artist;
use App\Models\AudioMetadataProposal;
use App\Models\File;
use App\Models\MetadataAlias;
use Illuminate\Database\Eloquent\Model as EloquentModel;

class AudioMetadataAliasService
{
    public function __construct(
        private readonly AudioMetadataValueExtractor $values,
    ) {}

    /**
     * @return list<string>
     */
    public function values(mixed $aliases, string $field): array
    {
        return collect($aliases)
            ->filter(fn (mixed $alias): bool => $alias instanceof MetadataAlias && $alias->field === $field)
            ->map(fn (MetadataAlias $alias): string => trim($alias->value))
            ->filter(fn (string $alias): bool => $alias !== '')
            ->unique(fn (string $alias): string => MetadataAlias::normalizeValue($alias))
            ->values()
            ->all();
    }

    /**
     * @return array{title_aliases:list<string>,artist_aliases:list<string>,album_aliases:list<string>}
     */
    public function currentValues(File $file): array
    {
        return [
            'title_aliases' => $this->values($file->metadataAliases, 'title'),
            'artist_aliases' => $this->values($file->artists->flatMap->metadataAliases, 'name'),
            'album_aliases' => $this->values($file->albums->flatMap->metadataAliases, 'name'),
        ];
    }

    /**
     * @param  array<string, mixed>  $proposed
     * @param  list<string>  $fields
     */
    public function applySelected(File $file, AudioMetadataProposal $proposal, array $proposed, array $fields): void
    {
        $sourceId = $this->values->cleanString(data_get($proposal->evidence, 'discogs_release_id'))
            ?? $this->values->cleanString(data_get($proposal->evidence, 'musicbrainz_release_id'))
            ?? $this->values->cleanString(data_get($proposal->evidence, 'musicbrainz_recording_id'));

        if (in_array('title_aliases', $fields, true)) {
            $this->store($file, 'title', $proposed['title_aliases'] ?? [], 'search_alias', $proposal->provider, $sourceId, $file->title);
        }

        if (in_array('album_aliases', $fields, true)) {
            $album = $file->albums->first();
            if ($album instanceof Album) {
                $this->store($album, 'name', $proposed['album_aliases'] ?? [], 'search_alias', $proposal->provider, $sourceId, $album->name);
            }
        }

        if (in_array('artist_aliases', $fields, true)) {
            $this->applyArtistAliases($file, $proposal, $proposed, $sourceId);
        }
    }

    /**
     * @param  array<string, mixed>  $proposed
     */
    private function applyArtistAliases(File $file, AudioMetadataProposal $proposal, array $proposed, ?string $sourceId): void
    {
        $file->load('artists.metadataAliases');
        $storedMappedAliases = false;

        foreach ($this->artistAliasMap($proposed['artist_alias_map'] ?? []) as $artistName => $aliases) {
            $artist = $file->artists->firstWhere('name', $artistName);
            if ($artist instanceof Artist) {
                $this->store($artist, 'name', $aliases, 'search_alias', $proposal->provider, $sourceId, $artist->name);
                $storedMappedAliases = true;
            }
        }

        if ($storedMappedAliases || $file->artists->count() !== 1) {
            return;
        }

        $artist = $file->artists->first();
        if ($artist instanceof Artist) {
            $this->store($artist, 'name', $proposed['artist_aliases'] ?? [], 'search_alias', $proposal->provider, $sourceId, $artist->name);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function artistAliasMap(mixed $map): array
    {
        if (! is_array($map)) {
            return [];
        }

        $cleanMap = [];
        foreach ($map as $artist => $aliases) {
            $artist = $this->values->cleanString($artist);
            if ($artist === null) {
                continue;
            }

            $aliases = $this->values->cleanStringList($aliases);
            if ($aliases !== []) {
                $cleanMap[$artist] = $aliases;
            }
        }

        return $cleanMap;
    }

    public function store(
        EloquentModel $model,
        string $field,
        mixed $values,
        string $kind,
        ?string $source = null,
        ?string $sourceId = null,
        ?string $canonicalValue = null,
    ): void {
        $canonical = $canonicalValue !== null ? MetadataAlias::normalizeValue($canonicalValue) : null;

        foreach ($this->values->cleanStringList($values) as $value) {
            $normalized = MetadataAlias::normalizeValue($value);
            if ($normalized === '' || $normalized === $canonical) {
                continue;
            }

            MetadataAlias::query()->updateOrCreate([
                'aliasable_type' => $model::class,
                'aliasable_id' => $model->getKey(),
                'field' => $field,
                'normalized_value' => $normalized,
            ], [
                'value' => $value,
                'kind' => $kind,
                'source' => $source,
                'source_id' => $sourceId,
            ]);
        }
    }
}
