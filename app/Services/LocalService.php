<?php

namespace App\Services;

use App\Models\File;

class LocalService extends BaseService
{
    public const string KEY = 'local';

    public const string SOURCE = 'Local';

    public const string LABEL = 'Local Files';

    /**
     * Fetch local files from search index.
     *
     * Do not use Eloquent queries here. The database has over 1 million file records,
     * and direct queries are too slow for browse operations.
     */
    public function fetch(array $params = []): array
    {
        $this->params = $params;

        $page = (int) ($params['page'] ?? 1);
        $limit = (int) ($params['limit'] ?? 20);
        $source = $params['source'] ?? null; // Filter by source if provided
        $downloaded = $params['downloaded'] ?? 'any';
        $blacklisted = $params['blacklisted'] ?? 'any';
        $sort = is_string($params['sort'] ?? null) ? (string) $params['sort'] : 'downloaded_at';
        $seedRaw = $params['seed'] ?? null;
        $seed = is_numeric($seedRaw) ? (int) $seedRaw : null;
        $reaction = $params['reaction'] ?? null;

        // Filter by current user's reaction types (optional)
        $allTypes = ['love', 'like', 'dislike', 'funny'];
        $reactionTypes = null;
        if (is_array($reaction)) {
            $reactionTypes = array_values(array_unique(array_filter(array_map(fn ($v) => is_string($v) ? $v : (is_numeric($v) ? (string) $v : ''), $reaction))));
        } elseif (is_string($reaction) && $reaction !== '') {
            $reactionTypes = [$reaction];
        }

        if ($reactionTypes !== null) {
            $reactionTypes = array_values(array_filter($reactionTypes, fn ($t) => in_array($t, $allTypes, true)));

            if (count($reactionTypes) === 0) {
                return [
                    'files' => [],
                    'metadata' => [
                        'nextCursor' => null,
                        'total' => 0,
                    ],
                ];
            }

            // When all reaction types are selected (default), treat this as "no filter".
            if (count($reactionTypes) === count($allTypes)) {
                $reactionTypes = null;
            }
        }

        // Stabilize random sort by generating a seed once and letting Browser persist it into the tab params.
        if ($sort === 'random' && (! is_int($seed) || $seed < 1)) {
            $seed = time();
            $this->params['seed'] = $seed;
        }

        // Only Typesense can efficiently browse the entire dataset without direct DB queries.
        // For non-Typesense drivers (testing/dev fallbacks), use Eloquent so filters like blacklisted_at work.
        if (config('scout.driver') !== 'typesense') {
            return $this->fetchUsingDatabase(
                page: $page,
                limit: $limit,
                source: is_string($source) ? $source : null,
                downloaded: is_string($downloaded) ? $downloaded : 'any',
                blacklisted: is_string($blacklisted) ? $blacklisted : 'any',
                sort: $sort,
                seed: $seed,
                reactionTypes: $reactionTypes,
                allTypes: $allTypes,
            );
        }

        $buildSearch = function () use ($params, $source, $downloaded, $blacklisted, $sort, $seed) {
            $search = $params['search'] ?? '';
            if ($search === '') {
                $search = config('scout.driver') === 'typesense' ? '*' : '';
            }
            $builder = File::search($search);

            // Filter by source if provided and not 'all'
            if ($source && $source !== 'all') {
                $builder->where('source', $source);
            }

            // Filter by downloaded tri-state
            if ($downloaded === 'yes') {
                $builder->where('downloaded', true);
            } elseif ($downloaded === 'no') {
                $builder->where('downloaded', false);
            }

            // Filter by blacklisted tri-state
            if ($blacklisted === 'yes') {
                $builder->where('blacklisted', true);
            } elseif ($blacklisted === 'no') {
                $builder->where('blacklisted', false);
            }

            // Sorting (Typesense supports special sort fields like _rand(seed)).
            $driver = config('scout.driver');
            if ($sort === 'random' && $driver === 'typesense') {
                $rand = $seed && $seed > 0 ? "_rand({$seed})" : '_rand()';
                $builder->orderBy($rand, 'desc');
            } elseif ($sort === 'updated_at') {
                $builder->orderBy('updated_at', 'desc');
            } elseif ($sort === 'blacklisted_at') {
                $builder->orderBy('blacklisted_at', 'desc')
                    ->orderBy('updated_at', 'desc');
            } else {
                // Default sort: newest downloads first, then recently updated.
                $builder->orderBy('downloaded_at', 'desc')
                    ->orderBy('updated_at', 'desc');
            }

            // Important: do NOT set a Scout query callback here.
            // When a query callback is present, Scout will attempt to compute pagination totals by
            // enumerating IDs (bounded by Scout's max_total_results, default 1000), which makes the
            // "Total" shown in local browse incorrect for large datasets. We load metadata after the
            // fact in Browser.php for local mode, per-page.

            return $builder;
        };

        if ($reactionTypes !== null) {
            if (count($reactionTypes) < count($allTypes)) {
                $userId = auth()->id();
                if (! $userId) {
                    return [
                        'files' => [],
                        'metadata' => [
                            'nextCursor' => null,
                            'total' => 0,
                        ],
                    ];
                }

                if (count($reactionTypes) === 1) {
                    $reactionField = "{$reactionTypes[0]}_user_ids";
                    $pagination = $buildSearch()
                        ->where($reactionField, (string) $userId)
                        ->paginate($limit, 'page', $page);

                    $files = collect($pagination->items());
                    $nextCursor = $pagination->hasMorePages() ? $pagination->currentPage() + 1 : null;

                    $filesList = $files->all();
                    $driver = config('scout.driver');
                    if ($sort === 'random' && $driver !== 'typesense') {
                        $seedValue = $seed && $seed > 0 ? (string) $seed : (string) time();
                        $filesList = collect($filesList)
                            ->sortBy(fn (File $f) => sprintf('%u', crc32($seedValue.':'.$f->id)))
                            ->values()
                            ->all();
                    }

                    return [
                        'files' => $filesList,
                        'metadata' => [
                            'nextCursor' => $nextCursor,
                            'total' => method_exists($pagination, 'total') ? (int) $pagination->total() : null,
                        ],
                    ];
                }

                $targetLimit = $page * $limit;
                $results = collect();
                $total = 0;

                foreach ($reactionTypes as $type) {
                    $reactionField = "{$type}_user_ids";
                    $pagination = $buildSearch()
                        ->where($reactionField, (string) $userId)
                        ->paginate($targetLimit, 'page', 1);

                    $results = $results->merge($pagination->items());
                    $total += $pagination->total();
                }

                $files = $results
                    ->unique('id')
                    ->when($sort === 'random', function ($c) use ($seed) {
                        $seedValue = $seed && $seed > 0 ? (string) $seed : (string) time();
                        return $c->sortBy(fn (File $f) => sprintf('%u', crc32($seedValue.':'.$f->id)));
                    }, function ($c) use ($sort) {
                        if ($sort === 'updated_at') {
                            return $c->sortByDesc(fn (File $f) => $f->updated_at?->timestamp ?? 0);
                        }

                        if ($sort === 'blacklisted_at') {
                            return $c->sortByDesc(fn (File $f) => $f->blacklisted_at?->timestamp ?? 0)
                                ->sortByDesc(fn (File $f) => $f->updated_at?->timestamp ?? 0);
                        }

                        return $c->sort(function (File $a, File $b) {
                            $aDownloaded = $a->downloaded_at?->timestamp ?? 0;
                            $bDownloaded = $b->downloaded_at?->timestamp ?? 0;
                            if ($aDownloaded !== $bDownloaded) {
                                return $bDownloaded <=> $aDownloaded;
                            }

                            $aUpdated = $a->updated_at?->timestamp ?? 0;
                            $bUpdated = $b->updated_at?->timestamp ?? 0;

                            return $bUpdated <=> $aUpdated;
                        });
                    })
                    ->values();

                $totalPages = (int) ceil($total / $limit);
                $filesPage = $files->slice(($page - 1) * $limit, $limit)->values();
                $nextCursor = $page < $totalPages ? $page + 1 : null;

                return [
                    'files' => $filesPage->all(),
                    'metadata' => [
                        'nextCursor' => $nextCursor,
                        'total' => $total,
                    ],
                ];
            }
        }

        $pagination = $buildSearch()->paginate($limit, 'page', $page);
        $files = collect($pagination->items());
        $nextCursor = $pagination->hasMorePages() ? $pagination->currentPage() + 1 : null;

        $filesList = $files->all();
        $driver = config('scout.driver');
        if ($sort === 'random' && $driver !== 'typesense') {
            $seedValue = $seed && $seed > 0 ? (string) $seed : (string) time();
            $filesList = collect($filesList)
                ->sortBy(fn (File $f) => sprintf('%u', crc32($seedValue.':'.$f->id)))
                ->values()
                ->all();
        }

        // Return files directly - Browser.php will use FileItemFormatter
        return [
            'files' => $filesList, // Return File models directly
            'metadata' => [
                'nextCursor' => $nextCursor,
                'total' => method_exists($pagination, 'total') ? (int) $pagination->total() : null,
            ],
        ];
    }

    /**
     * Fallback for non-Typesense Scout drivers.
     *
     * This is slower than Typesense but keeps correctness for tests/dev environments
     * (e.g. blacklisted filtering relies on blacklisted_at, not a virtual "blacklisted" column).
     *
     * @param  array<int, string>|null  $reactionTypes
     * @param  array<int, string>  $allTypes
     */
    protected function fetchUsingDatabase(
        int $page,
        int $limit,
        ?string $source,
        string $downloaded,
        string $blacklisted,
        string $sort,
        ?int $seed,
        ?array $reactionTypes,
        array $allTypes,
    ): array {
        $page = max(1, $page);
        $limit = max(1, $limit);

        $query = File::query()->with('metadata');

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

        if ($reactionTypes !== null && count($reactionTypes) < count($allTypes)) {
            $userId = auth()->id();
            if (! $userId) {
                return [
                    'files' => [],
                    'metadata' => [
                        'nextCursor' => null,
                        'total' => 0,
                    ],
                ];
            }

            $query->whereHas('reactions', function ($q) use ($userId, $reactionTypes) {
                $q->where('user_id', $userId)->whereIn('type', $reactionTypes);
            });
        }

        if ($sort === 'random') {
            $query->inRandomOrder();
        } elseif ($sort === 'updated_at') {
            $query->orderBy('updated_at', 'desc');
        } elseif ($sort === 'blacklisted_at') {
            $query->orderBy('blacklisted_at', 'desc')->orderBy('updated_at', 'desc');
        } else {
            $query->orderBy('downloaded_at', 'desc')->orderBy('updated_at', 'desc');
        }

        $pagination = $query->paginate($limit, ['*'], 'page', $page);
        $nextCursor = $pagination->hasMorePages() ? $pagination->currentPage() + 1 : null;

        $filesList = collect($pagination->items())->all();
        if ($sort === 'random') {
            $seedValue = $seed && $seed > 0 ? (string) $seed : (string) time();
            $filesList = collect($filesList)
                ->sortBy(fn (File $f) => sprintf('%u', crc32($seedValue.':'.$f->id)))
                ->values()
                ->all();
        }

        return [
            'files' => $filesList,
            'metadata' => [
                'nextCursor' => $nextCursor,
                'total' => (int) $pagination->total(),
            ],
        ];
    }

    /**
     * Return a normalized structure with files and next cursor.
     * For local mode, we return File models directly (no transformation needed).
     */
    public function transform(array $response, array $params = []): array
    {
        $files = $response['files'] ?? [];
        $nextCursor = $response['metadata']['nextCursor'] ?? null;
        $total = $response['metadata']['total'] ?? null;
        $total = is_numeric($total) ? (int) $total : null;

        // For local mode, files are already File models, so return them directly
        // Browser.php will handle formatting with FileItemFormatter
        return [
            'files' => $files,
            'filter' => [
                ...$this->params,
                'next' => $nextCursor,
            ],
            'meta' => [
                'total' => $total,
            ],
        ];
    }

    /**
     * Transform item to file format expected by Browser.
     * For local mode, we return File models directly (not transformed format).
     */
    protected function transformItemToFileFormat(array $item): File
    {
        $fileId = $item['id'] ?? null;
        $file = $fileId
            ? File::search('*')
                ->where('id', (string) $fileId)
                ->query(fn ($query) => $query->with('metadata'))
                ->get()
                ->first()
            : null;

        if (! $file) {
            throw new \RuntimeException("File with ID {$fileId} not found");
        }

        return $file;
    }

    public function defaultParams(): array
    {
        return [
            'limit' => 20,
            'source' => 'all', // Default to all sources
        ];
    }
}
