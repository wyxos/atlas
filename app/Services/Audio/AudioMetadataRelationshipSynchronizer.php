<?php

namespace App\Services\Audio;

use App\Models\Album;
use App\Models\Artist;
use App\Models\File;
use Illuminate\Database\Eloquent\Builder;

class AudioMetadataRelationshipSynchronizer
{
    public function __construct(
        private readonly AudioMetadataAliasService $aliases,
    ) {}

    /**
     * @param  list<string>  $names
     */
    public function syncArtists(File $file, array $names): void
    {
        if (count($names) === 1 && $file->artists->count() === 1) {
            $name = $names[0];
            $normalizedName = $this->normalizeName($name);
            $currentArtist = $file->artists->first();
            $existingArtist = Artist::query()
                ->where('normalized_name', $normalizedName)
                ->first();

            if ($existingArtist instanceof Artist && $currentArtist instanceof Artist && $existingArtist->isNot($currentArtist)) {
                $this->aliases->store($existingArtist, 'name', [$currentArtist->name], 'previous_import', 'atlas', null, $existingArtist->name);
                $file->artists()->sync([$existingArtist->id]);

                return;
            }

            if ($currentArtist instanceof Artist) {
                $this->aliases->store($currentArtist, 'name', [$currentArtist->name], 'previous_import', 'atlas', null, $name);
                $currentArtist->forceFill([
                    'name' => $name,
                    'normalized_name' => $normalizedName,
                ])->save();

                $file->artists()->sync([$currentArtist->id]);

                return;
            }
        }

        $artistIds = collect($names)
            ->map(fn (string $name): int => (int) Artist::query()->firstOrCreate([
                'normalized_name' => $this->normalizeName($name),
            ], [
                'name' => $name,
            ])->id)
            ->all();

        $file->artists()->sync($artistIds);
    }

    /**
     * @param  array<string, mixed>  $proposed
     */
    public function syncAlbum(File $file, string $name, array $proposed): void
    {
        $normalizedName = $this->normalizeName($name);
        $artistIds = $file->artists->pluck('id')->filter()->values();
        $file->loadMissing('albums');
        $currentAlbumNames = $file->albums
            ->map(fn (Album $album): string => trim($album->name))
            ->filter(fn (string $name): bool => $name !== '')
            ->values()
            ->all();
        $currentAlbum = $file->albums->count() === 1 ? $file->albums->first() : null;

        $album = $this->albumForRelease($proposed)
            ?? $this->albumForNameAndArtists($normalizedName, $artistIds->all())
            ?? $this->albumForAliasAndArtists($normalizedName, $artistIds->all());

        if (! $album instanceof Album && $currentAlbum instanceof Album) {
            $album = $currentAlbum;
        }

        $album ??= Album::query()->create([
            'name' => $name,
            'normalized_name' => $normalizedName,
        ]);

        if ($album->name !== $name || $album->normalized_name !== $normalizedName) {
            $this->aliases->store($album, 'name', [$album->name], 'previous_import', 'atlas', null, $name);
            $album->forceFill([
                'name' => $name,
                'normalized_name' => $normalizedName,
            ])->save();
        }

        $file->albums()->sync([$album->id]);
        $this->aliases->store($album, 'name', $currentAlbumNames, 'previous_import', 'atlas', null, $album->name);
    }

    /**
     * @param  array<string, mixed>  $proposed
     */
    private function albumForRelease(array $proposed): ?Album
    {
        $discogsReleaseId = $this->cleanString($proposed['discogs_release_id'] ?? null);
        if ($discogsReleaseId !== null) {
            $album = Album::query()
                ->withCount('files')
                ->where('discogs_release_id', $discogsReleaseId)
                ->orderByDesc('files_count')
                ->orderBy('id')
                ->first();

            if ($album instanceof Album) {
                return $album;
            }
        }

        $musicBrainzReleaseId = $this->cleanString($proposed['musicbrainz_release_id'] ?? null);
        if ($musicBrainzReleaseId !== null) {
            $album = Album::query()
                ->withCount('files')
                ->where('musicbrainz_release_id', $musicBrainzReleaseId)
                ->orderByDesc('files_count')
                ->orderBy('id')
                ->first();

            if ($album instanceof Album) {
                return $album;
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $artistIds
     */
    private function albumForNameAndArtists(string $normalizedName, array $artistIds): ?Album
    {
        if ($artistIds === []) {
            return null;
        }

        return Album::query()
            ->withCount('files')
            ->where('normalized_name', $normalizedName)
            ->whereHas('files.artists', fn (Builder $query) => $query->whereKey($artistIds))
            ->orderByDesc('files_count')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  list<int>  $artistIds
     */
    private function albumForAliasAndArtists(string $normalizedName, array $artistIds): ?Album
    {
        if ($artistIds === []) {
            return null;
        }

        return Album::query()
            ->withCount('files')
            ->whereHas('metadataAliases', fn (Builder $query) => $query
                ->where('field', 'name')
                ->where('normalized_value', $normalizedName))
            ->whereHas('files.artists', fn (Builder $query) => $query->whereKey($artistIds))
            ->orderByDesc('files_count')
            ->orderBy('id')
            ->first();
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $clean = preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';

        return $clean !== '' ? $clean : null;
    }

    private function normalizeName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(trim($name))) ?? '');
    }
}
