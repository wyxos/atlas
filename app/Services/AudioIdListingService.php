<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Support\Facades\DB;

class AudioIdListingService
{
    /**
     * Fetch audio file IDs with cursor metadata.
     *
     * NOTE FOR FUTURE CODEX INSTANCES:
     * Do not switch this to Eloquent. Keep this query-builder only and ID-only,
     * similar to LocalService, because the files dataset is large.
     */
    public function fetch(int $afterId, int $perPage, ?int $maxId = null): array
    {
        $afterId = max(0, $afterId);
        $perPage = min(1000, max(1, $perPage));
        $maxId = $maxId === null
            ? (int) DB::table('files')
                ->where('mime_type', 'like', 'audio/%')
                ->max('id')
            : max(0, $maxId);

        $rows = DB::table('files')
            ->select('id')
            ->where('mime_type', 'like', 'audio/%')
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

        $nextAfterId = $hasMore && $ids !== []
            ? $ids[array_key_last($ids)]
            : null;
        $total = $afterId === 0
            ? (int) DB::table('files')
                ->where('mime_type', 'like', 'audio/%')
                ->where('id', '<=', $maxId)
                ->count()
            : null;
        $totalPages = $total !== null
            ? (int) ceil($total / max(1, $perPage))
            : null;

        return [
            'ids' => $ids,
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
     *         albums: list<string>
     *     }>
     * }
     */
    public function fetchDetails(array $ids): array
    {
        $ids = array_values(array_filter(array_unique(array_map(
            static fn ($id): int => (int) $id,
            $ids
        )), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return ['items' => []];
        }

        $filesById = File::query()
            ->select(['id', 'title', 'filename', 'source'])
            ->whereIn('id', $ids)
            ->where('mime_type', 'like', 'audio/%')
            ->with([
                'metadata:file_id,payload',
                'containers:id,type,source_id',
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

            $artists = array_values(array_unique([...$artists, ...$containerArtists]));
            $albums = array_values(array_unique([...$albums, ...$containerAlbums]));

            $title = trim((string) (data_get($payload, 'title') ?? $file->title ?? $file->filename ?? ''));
            $source = trim((string) ($file->source ?? ''));

            $items[] = [
                'id' => (int) $file->id,
                'title' => $title !== '' ? $title : null,
                'source' => $source !== '' ? $source : null,
                'artists' => $artists,
                'albums' => $albums,
            ];
        }

        return ['items' => $items];
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
