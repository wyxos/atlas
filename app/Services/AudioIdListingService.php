<?php

namespace App\Services;

use App\Models\File;
use App\Services\Playlists\AudioPlaylistQueryService;
use App\Support\FileApiPath;

class AudioIdListingService
{
    private const string AUDIO_ID_PAGE_INDEX = 'files_mime_type_id_index';

    public function __construct(
        private readonly AudioPlaylistQueryService $playlistQuery,
    ) {}

    /**
     * Fetch audio file IDs with cursor metadata.
     *
     * NOTE FOR FUTURE CODEX INSTANCES:
     * Do not switch this to Eloquent. Keep this query-builder only and ID-only,
     * similar to LocalService, because the files dataset is large.
     */
    public function fetch(
        int $afterId,
        int $perPage,
        ?int $maxId = null,
        ?string $playlistSlug = null,
        ?int $userId = null,
    ): array {
        $afterId = max(0, $afterId);
        $perPage = min(1000, max(1, $perPage));
        $baseQuery = $this->playlistQuery->baseAudioQuery();
        if ($playlistSlug !== null && $userId !== null) {
            $this->playlistQuery->applySlug($baseQuery, $userId, $playlistSlug);
        }

        $maxId = $maxId === null
            ? (int) (clone $baseQuery)->max('id')
            : max(0, $maxId);

        $rows = (clone $baseQuery)
            ->forceIndex(self::AUDIO_ID_PAGE_INDEX)
            ->select(['id', 'source'])
            ->where('id', '>', $afterId)
            ->where('id', '<=', $maxId)
            ->orderBy('id')
            ->limit($perPage + 1)
            ->get();

        $hasMore = $rows->count() > $perPage;
        $chunk = $hasMore ? $rows->slice(0, $perPage)->values() : $rows;

        $ids = array_map(
            static fn ($row): int => (int) $row->id,
            $chunk->all(),
        );
        $sourcesById = [];
        foreach ($chunk as $row) {
            $source = trim((string) ($row->source ?? ''));
            $sourcesById[(int) $row->id] = $source !== '' ? $source : null;
        }

        $nextAfterId = $hasMore && $ids !== []
            ? $ids[array_key_last($ids)]
            : null;
        $total = $afterId === 0
            ? (int) (clone $baseQuery)
                ->where('id', '<=', $maxId)
                ->count()
            : null;
        $totalPages = $total !== null
            ? (int) ceil($total / max(1, $perPage))
            : null;

        return [
            'ids' => $ids,
            'sources' => $sourcesById,
            'cursor' => [
                'after_id' => $afterId,
                'next_after_id' => $nextAfterId,
                'has_more' => $hasMore,
                'max_id' => $maxId,
            ],
            'pagination' => [
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ];
    }

    /**
     * Fetch audio file details for the requested IDs.
     *
     * @param  list<int>  $ids
     * @return array{
     *     items: list<array{
     *         id: int,
     *         title: string|null,
     *         source: string|null,
     *         artists: list<string>,
     *         albums: list<string>,
     *         cover_url: string|null,
     *         duration_seconds: int|null,
     *         reaction: array{type:string}|null,
     *         blacklisted_at: string|null,
     *         previewed_count: int,
     *         seen_count: int
     *     }>
     * }
     */
    public function fetchDetails(array $ids, ?int $userId = null): array
    {
        $ids = array_values(array_filter(array_unique(array_map(
            static fn ($id): int => (int) $id,
            $ids
        )), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return ['items' => []];
        }

        $filesById = File::query()
            ->select([
                'id',
                'title',
                'filename',
                'source',
                'listing_metadata',
                'preview_url',
                'preview_path',
                'poster_path',
                'blacklisted_at',
                'previewed_count',
                'seen_count',
            ])
            ->whereIn('id', $ids)
            ->where('mime_type', 'like', 'audio/%')
            ->with([
                'metadata:file_id,payload',
                'containers:id,type,source_id',
                'artists:id,name',
                'albums:id,name',
                'albums.defaultCover:id,album_id,path,mime_type',
                'reactions' => fn ($query) => $query
                    ->select(['id', 'file_id', 'user_id', 'type'])
                    ->where('user_id', $userId ?? 0),
            ])
            ->get()
            ->keyBy('id');

        $items = [];
        foreach ($ids as $id) {
            $file = $filesById->get($id);
            if (! $file) {
                continue;
            }

            $payload = $file->metadata?->payload;
            if (! is_array($payload)) {
                $payload = [];
            }

            $artists = $this->normalizeStringList([
                data_get($payload, 'artist'),
                data_get($payload, 'artists'),
                data_get($payload, 'album_artist'),
                data_get($payload, 'albumArtist'),
                data_get($payload, 'albumartist'),
                data_get($payload, 'performer'),
            ]);

            $albums = $this->normalizeStringList([
                data_get($payload, 'album'),
                data_get($payload, 'albums'),
            ]);

            $containerArtists = $file->containers
                ->filter(fn ($container): bool => strtolower(trim((string) $container->type)) === 'artist')
                ->map(fn ($container): string => trim((string) $container->source_id))
                ->filter(fn (string $name): bool => $name !== '')
                ->values()
                ->all();

            $containerAlbums = $file->containers
                ->filter(fn ($container): bool => strtolower(trim((string) $container->type)) === 'album')
                ->map(fn ($container): string => trim((string) $container->source_id))
                ->filter(fn (string $name): bool => $name !== '')
                ->values()
                ->all();

            $relationshipArtists = $file->artists
                ->map(fn ($artist): string => trim((string) $artist->name))
                ->filter(fn (string $name): bool => $name !== '')
                ->values()
                ->all();
            $relationshipAlbums = $file->albums
                ->map(fn ($album): string => trim((string) $album->name))
                ->filter(fn (string $name): bool => $name !== '')
                ->values()
                ->all();

            $artists = $relationshipArtists !== []
                ? array_values(array_unique($relationshipArtists))
                : array_values(array_unique([...$artists, ...$containerArtists]));
            $albums = $relationshipAlbums !== []
                ? array_values(array_unique($relationshipAlbums))
                : array_values(array_unique([...$albums, ...$containerAlbums]));

            $title = trim((string) (data_get($payload, 'title') ?? $file->title ?? $file->filename ?? ''));
            $source = trim((string) ($file->source ?? ''));
            $reaction = $file->reactions->first();

            $items[] = [
                'id' => (int) $file->id,
                'title' => $title !== '' ? $title : null,
                'source' => $source !== '' ? $source : null,
                'artists' => $artists,
                'albums' => $albums,
                'cover_url' => $this->coverUrl($file),
                'duration_seconds' => $this->durationSeconds($file, $payload),
                'reaction' => $reaction ? ['type' => (string) $reaction->type] : null,
                'blacklisted_at' => $file->blacklisted_at?->toIso8601String(),
                'previewed_count' => (int) ($file->previewed_count ?? 0),
                'seen_count' => (int) ($file->seen_count ?? 0),
            ];
        }

        return ['items' => $items];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function durationSeconds(File $file, array $payload): ?int
    {
        $seconds = data_get($payload, 'duration_seconds')
            ?? data_get($payload, 'duration')
            ?? data_get($payload, 'probe.format.duration')
            ?? data_get($file->listing_metadata, 'duration_seconds');

        if (! is_numeric($seconds)) {
            $streams = data_get($payload, 'probe.streams');
            if (is_array($streams)) {
                foreach ($streams as $stream) {
                    $streamDuration = is_array($stream) ? ($stream['duration'] ?? null) : null;
                    if (is_numeric($streamDuration)) {
                        $seconds = $streamDuration;
                        break;
                    }
                }
            }
        }

        if (! is_numeric($seconds)) {
            return null;
        }

        $normalized = (int) round((float) $seconds);

        return $normalized > 0 ? $normalized : null;
    }

    private function coverUrl(File $file): ?string
    {
        $albumCover = $file->albums
            ->map(fn ($album) => $album->defaultCover)
            ->filter()
            ->first();

        if ($albumCover) {
            return FileApiPath::albumCover((int) $albumCover->id);
        }

        if (is_string($file->poster_path) && trim($file->poster_path) !== '') {
            return FileApiPath::poster((int) $file->id);
        }

        if (is_string($file->preview_path) && trim($file->preview_path) !== '') {
            return FileApiPath::preview((int) $file->id);
        }

        $previewUrl = trim((string) ($file->preview_url ?? ''));

        return $previewUrl !== '' ? $previewUrl : null;
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function normalizeStringList(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            foreach ($this->flattenStringValues($value) as $entry) {
                $trimmed = trim($entry);
                if ($trimmed === '') {
                    continue;
                }

                $normalized[] = $trimmed;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return list<string>
     */
    private function flattenStringValues(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $parts = preg_split('/\s*[,;|]\s*/', $value) ?: [];
            $parts = array_values(array_filter($parts, static fn (string $part): bool => trim($part) !== ''));

            return $parts === [] ? [trim($value)] : $parts;
        }

        if (is_numeric($value)) {
            return [(string) $value];
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
}
