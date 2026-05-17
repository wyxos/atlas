<?php

namespace App\Services\Audio;

use App\Models\Album;
use App\Models\AlbumCover;
use App\Models\Artist;
use App\Models\File;
use App\Services\LibraryScans\MediaProbeService;
use App\Support\AtlasStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class AudioMetadataIngestionService
{
    public function __construct(
        private readonly AtlasStorage $appStorage,
        private readonly MediaProbeService $probe,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{artists:list<string>,albums:list<string>,album_covers:int}
     */
    public function ingest(File $file, array $payload, ?string $absolutePath = null): array
    {
        $artists = $this->extractNames($payload, [
            'artist',
            'artists',
            'album_artist',
            'albumArtist',
            'albumartist',
            'performer',
        ]);
        $albums = $this->extractNames($payload, ['album', 'albums']);

        $artistModels = $this->syncArtists($file, $artists);
        $albumModels = $this->syncAlbums($file, $albums, $artistModels);

        $this->syncTitle($file, $payload);

        $coverCount = $this->extractAlbumCovers($file, $payload, $albumModels, $absolutePath);

        return [
            'artists' => $artistModels->pluck('name')->values()->all(),
            'albums' => $albumModels->pluck('name')->values()->all(),
            'album_covers' => $coverCount,
        ];
    }

    /**
     * @param  list<string>  $names
     * @return Collection<int, Artist>
     */
    private function syncArtists(File $file, array $names): Collection
    {
        $artists = collect($names)
            ->map(fn (string $name): Artist => $this->firstOrCreateArtist($name))
            ->values();

        if ($artists->isNotEmpty()) {
            $file->artists()->sync($artists->pluck('id')->all());
        }

        return $artists;
    }

    /**
     * @param  list<string>  $names
     * @param  Collection<int, Artist>  $artists
     * @return Collection<int, Album>
     */
    private function syncAlbums(File $file, array $names, Collection $artists): Collection
    {
        $albums = collect($names)
            ->map(fn (string $name): Album => $this->firstOrCreateAlbum($file, $name, $artists))
            ->values();

        if ($albums->isNotEmpty()) {
            $file->albums()->sync($albums->pluck('id')->all());
        }

        return $albums;
    }

    private function firstOrCreateArtist(string $name): Artist
    {
        return Artist::query()->firstOrCreate([
            'normalized_name' => $this->normalizeName($name),
        ], [
            'name' => $name,
        ]);
    }

    /**
     * @param  Collection<int, Artist>  $artists
     */
    private function firstOrCreateAlbum(File $file, string $name, Collection $artists): Album
    {
        $normalizedName = $this->normalizeName($name);
        $existingAlbum = $file->albums()
            ->where('normalized_name', $normalizedName)
            ->first();

        if ($existingAlbum instanceof Album) {
            return $existingAlbum;
        }

        $artistIds = $artists->pluck('id')->filter()->values();
        if ($artistIds->isNotEmpty()) {
            $existingAlbum = Album::query()
                ->where('normalized_name', $normalizedName)
                ->whereHas('files.artists', function (Builder $query) use ($artistIds): void {
                    $query->whereKey($artistIds->all());
                })
                ->orderBy('id')
                ->first();

            if ($existingAlbum instanceof Album) {
                return $existingAlbum;
            }
        }

        return Album::query()->create([
            'name' => $name,
            'normalized_name' => $normalizedName,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function extractNames(array $payload, array $keys): array
    {
        return $this->uniqueNames($this->flattenStringValues($this->valuesForKeys($payload, $keys)));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncTitle(File $file, array $payload): void
    {
        $title = $this->firstStringValue($this->valuesForKeys($payload, ['title']));
        if ($title === null) {
            return;
        }

        $file->forceFill(['title' => $title])->save();
    }

    /**
     * @param  Collection<int, Album>  $albums
     */
    private function extractAlbumCovers(File $file, array $payload, Collection $albums, ?string $absolutePath): int
    {
        if ($absolutePath === null || ! is_file($absolutePath) || $albums->isEmpty()) {
            return 0;
        }

        $coverStreams = $this->coverStreams($payload);
        if ($coverStreams === []) {
            return 0;
        }

        $ffmpeg = $this->probe->resolveFfmpegPath();
        if ($ffmpeg === null) {
            return 0;
        }

        $extracted = 0;
        foreach (array_values($coverStreams) as $sortOrder => $stream) {
            $streamIndex = (int) ($stream['index'] ?? $sortOrder);
            $relativePath = $this->coverPath($file, $streamIndex);

            if (! $this->extractCoverImage($ffmpeg, $absolutePath, $streamIndex, $relativePath)) {
                continue;
            }

            $fullPath = Storage::disk(AtlasStorage::DISK)->path($relativePath);
            $hash = hash_file('sha256', $fullPath);
            if (! is_string($hash) || $hash === '') {
                continue;
            }

            $pictureType = $this->pictureType($stream);
            foreach ($albums as $album) {
                $cover = AlbumCover::query()->updateOrCreate([
                    'album_id' => $album->id,
                    'path_hash' => hash('sha256', $relativePath),
                ], [
                    'file_id' => $file->id,
                    'path' => $relativePath,
                    'hash' => $hash,
                    'size' => filesize($fullPath) ?: null,
                    'mime_type' => 'image/jpeg',
                    'picture_type' => $pictureType,
                    'sort_order' => $sortOrder,
                ]);

                $this->promoteDefaultCover($cover);
            }

            $extracted++;
        }

        return $extracted;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function coverStreams(array $payload): array
    {
        $streams = data_get($payload, 'probe.streams');
        if (! is_array($streams)) {
            return [];
        }

        $videoStreams = array_values(array_filter($streams, static fn (mixed $stream): bool => is_array($stream)
            && ($stream['codec_type'] ?? null) === 'video'));
        $attachedPictures = array_values(array_filter($videoStreams, static fn (array $stream): bool => (int) data_get($stream, 'disposition.attached_pic', 0) === 1));

        return $attachedPictures !== [] ? $attachedPictures : $videoStreams;
    }

    private function extractCoverImage(string $ffmpeg, string $absolutePath, int $streamIndex, string $relativePath): bool
    {
        $disk = Storage::disk(AtlasStorage::DISK);
        $directory = dirname($relativePath);
        if (! $disk->exists($directory)) {
            $disk->makeDirectory($directory, 0755, true);
        }

        $targetPath = $disk->path($relativePath);
        if (is_file($targetPath)) {
            return true;
        }

        $process = new Process([
            $ffmpeg,
            '-y',
            '-i',
            $absolutePath,
            '-map',
            "0:{$streamIndex}",
            '-frames:v',
            '1',
            '-q:v',
            '2',
            $targetPath,
        ]);
        $process->setTimeout((int) config('downloads.ffmpeg_timeout_seconds', 120));

        try {
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        return $process->isSuccessful() && is_file($targetPath);
    }

    private function promoteDefaultCover(AlbumCover $cover): void
    {
        $hasDefault = AlbumCover::query()
            ->where('album_id', $cover->album_id)
            ->where('is_default', true)
            ->exists();

        if ($cover->picture_type === 'front') {
            AlbumCover::query()
                ->where('album_id', $cover->album_id)
                ->whereKeyNot($cover->id)
                ->update(['is_default' => false]);

            $cover->forceFill(['is_default' => true])->save();

            return;
        }

        if (! $hasDefault) {
            $cover->forceFill(['is_default' => true])->save();
        }
    }

    private function coverPath(File $file, int $streamIndex): string
    {
        $baseName = pathinfo((string) $file->path, PATHINFO_FILENAME) ?: 'cover';

        return $this->appStorage->derivedPath(
            (string) $file->path,
            'covers',
            "{$baseName}-cover-{$streamIndex}.jpg",
        );
    }

    /**
     * @param  array<string, mixed>  $stream
     */
    private function pictureType(array $stream): ?string
    {
        $tags = is_array($stream['tags'] ?? null) ? $stream['tags'] : [];
        $label = mb_strtolower(implode(' ', array_filter(array_map(
            static fn (mixed $value): ?string => is_scalar($value) ? (string) $value : null,
            $tags,
        ))));

        return match (true) {
            str_contains($label, 'front') => 'front',
            str_contains($label, 'back') => 'back',
            str_contains($label, 'leaflet') => 'leaflet',
            str_contains($label, 'media') => 'media',
            default => null,
        };
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

        $streams = data_get($payload, 'probe.streams');
        if (is_array($streams)) {
            foreach ($streams as $stream) {
                $streamTags = is_array($stream) ? ($stream['tags'] ?? null) : null;
                if (is_array($streamTags)) {
                    $sources[] = $streamTags;
                }
            }
        }

        return $sources;
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function flattenStringValues(array $values): array
    {
        $flattened = [];

        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value) || is_numeric($value)) {
                $parts = preg_split('/\s*[;|]\s*/', (string) $value) ?: [];
                foreach ($parts as $part) {
                    $flattened[] = $part;
                }

                continue;
            }

            if (is_array($value)) {
                $flattened = [...$flattened, ...$this->flattenStringValues($value)];
            }
        }

        return $flattened;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function uniqueNames(array $names): array
    {
        $unique = [];

        foreach ($names as $name) {
            $clean = preg_replace('/\s+/', ' ', trim($name)) ?? '';
            if ($clean === '') {
                continue;
            }

            $unique[$this->normalizeName($clean)] = $clean;
        }

        return array_values($unique);
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstStringValue(array $values): ?string
    {
        foreach ($this->flattenStringValues($values) as $value) {
            $clean = preg_replace('/\s+/', ' ', trim($value)) ?? '';
            if ($clean !== '') {
                return $clean;
            }
        }

        return null;
    }

    private function normalizeName(string $name): string
    {
        $normalized = preg_replace('/\s+/', ' ', mb_strtolower(trim($name))) ?? '';

        return trim($normalized);
    }

    private function normalizeKey(string $key): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($key)) ?? '';
    }
}
