<?php

namespace App\Services\Local;

use App\Models\File;
use Illuminate\Database\Eloquent\Builder;

class LocalBrowseQueryBuilder
{
    private const int RANDOM_WINDOW_PAGES = 10;

    private const int RANDOM_SCAN_CHUNK = 500;

    public static function buildBaseQuery(
        ?string $source,
        string $downloaded,
        string $blacklisted,
        string $blacklistType,
        ?int $maxPreviewed,
        array $fileTypes,
    ): Builder {
        $query = File::query();

        if ($source && $source !== 'all') {
            $query->where('source', $source);
        }

        if ($downloaded === 'yes') {
            $query->where('downloaded', true);
        } elseif ($downloaded === 'no') {
            $query->where('downloaded', false);
        }

        if ($blacklisted === 'yes') {
            $query->whereNotNull('blacklisted_at');
        } elseif ($blacklisted === 'no') {
            $query->whereNull('blacklisted_at');
        }

        if (in_array($blacklistType, ['manual', 'auto'], true)) {
            $query->whereNotNull('blacklisted_at');

            if ($blacklistType === 'manual') {
                $query->whereNotNull('blacklist_reason')->where('blacklist_reason', '!=', '');
            } else {
                $query->where(function (Builder $builder): void {
                    $builder->whereNull('blacklist_reason')->orWhere('blacklist_reason', '=', '');
                });
            }
        }

        if (is_int($maxPreviewed) && $maxPreviewed >= 0) {
            $query->where('previewed_count', '<=', $maxPreviewed);
        }

        self::applyFileTypeFilter($query, $fileTypes);

        return $query;
    }

    public static function applyAutoDislikedFilter(Builder $query, string $autoDisliked): Builder
    {
        if ($autoDisliked === 'yes') {
            $query->where('auto_disliked', true);
        } elseif ($autoDisliked === 'no') {
            $query->where('auto_disliked', false);
        }

        return $query;
    }

    public static function applyStandardSort(Builder $query, string $sort): Builder
    {
        if ($sort === 'created_at') {
            $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');

            return $query;
        }

        if ($sort === 'created_at_asc') {
            $query->orderBy('created_at', 'asc')->orderBy('id', 'asc');

            return $query;
        }

        if ($sort === 'updated_at') {
            $query->orderBy('updated_at', 'desc')->orderBy('id', 'desc');

            return $query;
        }

        if ($sort === 'updated_at_asc') {
            $query->orderBy('updated_at', 'asc')->orderBy('id', 'asc');

            return $query;
        }

        if ($sort === 'blacklisted_at') {
            $query->orderBy('blacklisted_at', 'desc')
                ->orderBy('updated_at', 'desc')
                ->orderBy('id', 'desc');

            return $query;
        }

        if ($sort === 'blacklisted_at_asc') {
            $query->orderBy('blacklisted_at', 'asc')
                ->orderBy('updated_at', 'asc')
                ->orderBy('id', 'asc');

            return $query;
        }

        if ($sort === 'downloaded_at_asc') {
            $query->orderBy('downloaded_at', 'asc')
                ->orderBy('updated_at', 'asc')
                ->orderBy('id', 'asc');

            return $query;
        }

        $query->orderBy('downloaded_at', 'desc')
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc');

        return $query;
    }

    /**
     * @return array{ids: array<int>, nextCursor: int|null, total: int}
     */
    public static function paginateIds(Builder $query, int $page, int $limit): array
    {
        $pagination = (clone $query)
            ->select('files.id')
            ->paginate($limit, ['files.id'], 'page', $page);

        $ids = collect($pagination->items())
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return [
            'ids' => $ids,
            'nextCursor' => $pagination->hasMorePages() ? $pagination->currentPage() + 1 : null,
            'total' => (int) $pagination->total(),
        ];
    }

    /**
     * @return array{ids: array<int>, nextCursor: int|null, total: int}
     */
    public static function paginateRandomIds(Builder $query, int $page, int $limit, int $seed): array
    {
        $page = max(1, $page);
        $limit = max(1, $limit);

        $total = (int) (clone $query)->reorder()->count('files.id');
        if ($total === 0) {
            return [
                'ids' => [],
                'nextCursor' => null,
                'total' => 0,
            ];
        }

        $windowSize = $limit * self::RANDOM_WINDOW_PAGES;
        $windowIndex = intdiv($page - 1, self::RANDOM_WINDOW_PAGES);
        $pageIndexWithinWindow = ($page - 1) % self::RANDOM_WINDOW_PAGES;
        $skip = $windowIndex * $windowSize;

        if ($skip >= $total) {
            return [
                'ids' => [],
                'nextCursor' => null,
                'total' => $total,
            ];
        }

        [$minId, $maxId] = self::idBounds($query);
        if ($minId === null || $maxId === null) {
            return [
                'ids' => [],
                'nextCursor' => null,
                'total' => $total,
            ];
        }

        $anchorId = self::anchorId($minId, $maxId, $seed);
        $windowIds = self::scanSequentialIds($query, $anchorId, $skip, $windowSize);
        $windowSeed = self::stableUnsignedInt("random-window:{$seed}:{$windowIndex}");
        $shuffled = self::deterministicShuffle($windowIds, $windowSeed);
        $offsetWithinWindow = $pageIndexWithinWindow * $limit;
        $pageIds = array_slice($shuffled, $offsetWithinWindow, $limit);

        return [
            'ids' => $pageIds,
            'nextCursor' => $total > ($page * $limit) ? $page + 1 : null,
            'total' => $total,
        ];
    }

    /**
     * @param  array<int>  $ids
     * @return array<int, File>
     */
    public static function hydrateFiles(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $filesById = File::query()
            ->with('metadata')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return collect($ids)
            ->map(static fn (int $id): ?File => $filesById->get($id))
            ->filter()
            ->values()
            ->all();
    }

    public static function applyFileTypeFilter(Builder $query, array $fileTypes): Builder
    {
        if (in_array('all', $fileTypes, true)) {
            return $query;
        }

        $query->where(function (Builder $builder) use ($fileTypes): void {
            $hasClause = false;

            if (in_array('image', $fileTypes, true)) {
                $builder->orWhere('mime_type', 'like', 'image/%');
                $hasClause = true;
            }

            if (in_array('video', $fileTypes, true)) {
                $builder->orWhere('mime_type', 'like', 'video/%');
                $hasClause = true;
            }

            if (in_array('audio', $fileTypes, true)) {
                $builder->orWhere('mime_type', 'like', 'audio/%');
                $hasClause = true;
            }

            if (in_array('other', $fileTypes, true)) {
                $builder->orWhere(function (Builder $nested): void {
                    $nested->whereNull('mime_type')
                        ->orWhere('mime_type', '=', '')
                        ->orWhere(function (Builder $other): void {
                            $other->where('mime_type', 'not like', 'image/%')
                                ->where('mime_type', 'not like', 'video/%')
                                ->where('mime_type', 'not like', 'audio/%');
                        });
                });
                $hasClause = true;
            }

            if (! $hasClause) {
                $builder->orWhereRaw('1=1');
            }
        });

        return $query;
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private static function idBounds(Builder $query): array
    {
        $bounds = (clone $query)
            ->reorder()
            ->selectRaw('MIN(files.id) as min_id, MAX(files.id) as max_id')
            ->first();

        $minId = is_numeric($bounds?->min_id) ? (int) $bounds->min_id : null;
        $maxId = is_numeric($bounds?->max_id) ? (int) $bounds->max_id : null;

        return [$minId, $maxId];
    }

    private static function anchorId(int $minId, int $maxId, int $seed): int
    {
        $range = max(1, $maxId - $minId + 1);
        $offset = self::stableUnsignedInt("random-anchor:{$seed}") % $range;

        return $minId + $offset;
    }

    /**
     * @return array<int>
     */
    private static function scanSequentialIds(Builder $query, int $anchorId, int $skip, int $take): array
    {
        $collected = [];
        $remainingSkip = max(0, $skip);
        $remainingTake = max(0, $take);

        foreach ([
            ['operator' => '>=', 'threshold' => $anchorId],
            ['operator' => '<', 'threshold' => $anchorId],
        ] as $pass) {
            if ($remainingTake <= 0) {
                break;
            }

            $lastId = null;

            while ($remainingTake > 0) {
                $batch = (clone $query)
                    ->reorder()
                    ->select('files.id')
                    ->where('files.id', $pass['operator'], $pass['threshold'])
                    ->when($lastId !== null, fn (Builder $builder) => $builder->where('files.id', '>', $lastId))
                    ->orderBy('files.id')
                    ->limit(self::RANDOM_SCAN_CHUNK)
                    ->pluck('id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->values()
                    ->all();

                if ($batch === []) {
                    break;
                }

                $lastId = $batch[array_key_last($batch)];

                if ($remainingSkip > 0) {
                    if (count($batch) <= $remainingSkip) {
                        $remainingSkip -= count($batch);

                        continue;
                    }

                    $batch = array_slice($batch, $remainingSkip);
                    $remainingSkip = 0;
                }

                $takeFromBatch = min($remainingTake, count($batch));
                if ($takeFromBatch === 0) {
                    continue;
                }

                $collected = array_merge($collected, array_slice($batch, 0, $takeFromBatch));
                $remainingTake -= $takeFromBatch;
            }
        }

        return $collected;
    }

    /**
     * @param  array<int>  $ids
     * @return array<int>
     */
    private static function deterministicShuffle(array $ids, int $seed): array
    {
        $state = max(1, $seed);

        for ($index = count($ids) - 1; $index > 0; $index--) {
            $state = self::nextRandomState($state);
            $swapIndex = $state % ($index + 1);

            if ($swapIndex === $index) {
                continue;
            }

            [$ids[$index], $ids[$swapIndex]] = [$ids[$swapIndex], $ids[$index]];
        }

        return $ids;
    }

    private static function nextRandomState(int $state): int
    {
        return (int) (($state * 1103515245 + 12345) & 0x7FFFFFFF);
    }

    private static function stableUnsignedInt(string $value): int
    {
        return (int) sprintf('%u', crc32($value));
    }
}
