<?php

namespace App\Services;

use App\Models\File;
use App\Models\Reaction;

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
        $blacklistType = is_string($params['blacklist_type'] ?? null) ? (string) $params['blacklist_type'] : 'any';
        $sort = is_string($params['sort'] ?? null) ? (string) $params['sort'] : 'downloaded_at';
        $fileType = is_string($params['file_type'] ?? null) ? (string) $params['file_type'] : 'all';
        $seedRaw = $params['seed'] ?? null;
        $seed = is_numeric($seedRaw) ? (int) $seedRaw : null;
        $hasMaxPreviewedParam = array_key_exists('max_previewed_count', $params);
        $maxPreviewedRaw = $params['max_previewed_count'] ?? null;
        $maxPreviewed = is_numeric($maxPreviewedRaw) ? (int) $maxPreviewedRaw : null;
        // Allow 0 (fresh queue); negative values disable the filter.
        if (is_int($maxPreviewed) && $maxPreviewed < 0) {
            $maxPreviewed = null;
        }
        $reactionMode = is_string($params['reaction_mode'] ?? null) ? (string) $params['reaction_mode'] : 'any';
        $autoDisliked = is_string($params['auto_disliked'] ?? null) ? (string) $params['auto_disliked'] : 'any';
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
                $reactionTypes = $allTypes;
            }
        }

        if ($reactionMode === 'types' && ($reactionTypes === null || count($reactionTypes) === 0)) {
            return [
                'files' => [],
                'metadata' => [
                    'nextCursor' => null,
                    'total' => 0,
                ],
            ];
        }

        // "Reacted" is defined as positive reactions only (love/like/funny), excluding dislikes.
        // Keep it as a distinct mode so we can use a single Typesense filter field for pagination stability.

        // Default preview cap:
        // Most presets do not cap previewed_count (null). Disliked/Blacklisted use a bounded cap.
        if (! $hasMaxPreviewedParam) {
            $isDislikedView = $reactionMode === 'types'
                && is_array($reactionTypes)
                && in_array('dislike', $reactionTypes, true);

            $isBlacklistedView = $blacklisted === 'yes'
                || in_array($blacklistType, ['manual', 'auto'], true);

            $maxPreviewed = ($isDislikedView || $isBlacklistedView) ? 2 : null;
            $this->params['max_previewed_count'] = $maxPreviewed;
        }

        // Stabilize random sort by generating a seed once and letting Browser persist it into the tab params.
        if ($sort === 'random' && (! is_int($seed) || $seed < 1)) {
            $seed = time();
            $this->params['seed'] = $seed;
        }

        // Reaction timestamp sorting is inherently per-user and requires DB ordering.
        // This stays fast because it is scoped to the current user's reactions.
        if ($sort === 'reaction_at') {
            return $this->fetchByReactionTimestamp(
                page: $page,
                limit: $limit,
                source: is_string($source) ? $source : null,
                downloaded: is_string($downloaded) ? $downloaded : 'any',
                blacklisted: is_string($blacklisted) ? $blacklisted : 'any',
                blacklistType: $blacklistType,
                maxPreviewed: $maxPreviewed,
                autoDisliked: $autoDisliked,
                reactionMode: $reactionMode,
                reactionTypes: $reactionTypes,
                allTypes: $allTypes,
                sort: $sort,
            );
        }

        if ($sort === 'reaction_at_asc') {
            return $this->fetchByReactionTimestamp(
                page: $page,
                limit: $limit,
                source: is_string($source) ? $source : null,
                downloaded: is_string($downloaded) ? $downloaded : 'any',
                blacklisted: is_string($blacklisted) ? $blacklisted : 'any',
                blacklistType: $blacklistType,
                maxPreviewed: $maxPreviewed,
                autoDisliked: $autoDisliked,
                reactionMode: $reactionMode,
                reactionTypes: $reactionTypes,
                allTypes: $allTypes,
                sort: $sort,
            );
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
                blacklistType: $blacklistType,
                autoDisliked: $autoDisliked,
                sort: $sort,
                seed: $seed,
                maxPreviewed: $maxPreviewed,
                fileType: $fileType,
                reactionMode: $reactionMode,
                reactionTypes: $reactionTypes,
                allTypes: $allTypes,
            );
        }

        $buildSearch = function () use ($params, $source, $downloaded, $blacklisted, $blacklistType, $sort, $seed, $maxPreviewed, $fileType) {
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

            // Blacklist type (manual/auto) - only makes sense when blacklisted is allowed.
            if (in_array($blacklistType, ['manual', 'auto'], true)) {
                $builder->where('blacklisted', true);
                $builder->where('blacklist_type', $blacklistType);
            }

            // Cap previewed_count (optional).
            if (is_int($maxPreviewed) && $maxPreviewed >= 0) {
                $builder->where('previewed_count', ['<=', $maxPreviewed]);
            }

            // File type filter (optional, based on indexed mime_group).
            // Values: all, image, video, audio, image_video.
            if ($fileType === 'image') {
                $builder->where('mime_group', 'image');
            } elseif ($fileType === 'video') {
                $builder->where('mime_group', 'video');
            } elseif ($fileType === 'audio') {
                $builder->where('mime_group', 'audio');
            } elseif ($fileType === 'image_video') {
                $builder->where('mime_group', ['image', 'video']);
            }

            // Sorting (Typesense supports special sort fields like _rand(seed)).
            $driver = config('scout.driver');
            if ($sort === 'random' && $driver === 'typesense') {
                $rand = $seed && $seed > 0 ? "_rand({$seed})" : '_rand()';
                $builder->orderBy($rand, 'desc');
            } elseif ($sort === 'created_at_asc') {
                $builder->orderBy('created_at', 'asc');
            } elseif ($sort === 'created_at') {
                $builder->orderBy('created_at', 'desc');
            } elseif ($sort === 'updated_at') {
                $builder->orderBy('updated_at', 'desc');
            } elseif ($sort === 'updated_at_asc') {
                $builder->orderBy('updated_at', 'asc');
            } elseif ($sort === 'blacklisted_at') {
                $builder->orderBy('blacklisted_at', 'desc')
                    ->orderBy('updated_at', 'desc');
            } elseif ($sort === 'blacklisted_at_asc') {
                $builder->orderBy('blacklisted_at', 'asc')
                    ->orderBy('updated_at', 'asc');
            } elseif ($sort === 'downloaded_at_asc') {
                $builder->orderBy('downloaded_at', 'asc')
                    ->orderBy('updated_at', 'asc');
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

        // Auto-dislike tri-state filter (optional).
        $applyAutoDislikedFilter = function ($builder) use ($autoDisliked) {
            if ($autoDisliked === 'yes') {
                $builder->where('auto_disliked', true);
            } elseif ($autoDisliked === 'no') {
                $builder->where('auto_disliked', false);
            }

            return $builder;
        };

        // Unreacted: files you have not reacted to. This is per-user and depends on reacted_user_ids.
        if ($reactionMode === 'unreacted') {
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

            $pagination = $applyAutoDislikedFilter($buildSearch())
                ->whereNotIn('reacted_user_ids', [(string) $userId])
                ->paginate($limit, 'page', $page);

            return [
                'files' => collect($pagination->items())->all(),
                'metadata' => [
                    'nextCursor' => $pagination->hasMorePages() ? $pagination->currentPage() + 1 : null,
                    'total' => method_exists($pagination, 'total') ? (int) $pagination->total() : null,
                ],
            ];
        }

        // Reacted: positive only (love/like/funny). This is per-user and depends on positive_reacted_user_ids.
        if ($reactionMode === 'reacted') {
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

            $pagination = $applyAutoDislikedFilter($buildSearch())
                ->where('reacted_user_ids', (string) $userId)
                // Reacted excludes dislikes by definition.
                ->whereNotIn('dislike_user_ids', [(string) $userId])
                ->paginate($limit, 'page', $page);

            return [
                'files' => collect($pagination->items())->all(),
                'metadata' => [
                    'nextCursor' => $pagination->hasMorePages() ? $pagination->currentPage() + 1 : null,
                    'total' => method_exists($pagination, 'total') ? (int) $pagination->total() : null,
                ],
            ];
        }

        if ($reactionMode === 'types' && $reactionTypes !== null) {
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
                $pagination = $applyAutoDislikedFilter($buildSearch())
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
                $pagination = $applyAutoDislikedFilter($buildSearch())
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
                    if ($sort === 'created_at_asc') {
                        return $c->sortBy(fn (File $f) => $f->created_at?->timestamp ?? 0);
                    }
                    if ($sort === 'created_at') {
                        return $c->sortByDesc(fn (File $f) => $f->created_at?->timestamp ?? 0);
                    }
                    if ($sort === 'updated_at') {
                        return $c->sortByDesc(fn (File $f) => $f->updated_at?->timestamp ?? 0);
                    }
                    if ($sort === 'updated_at_asc') {
                        return $c->sortBy(fn (File $f) => $f->updated_at?->timestamp ?? 0);
                    }

                    if ($sort === 'blacklisted_at') {
                        return $c->sortByDesc(fn (File $f) => $f->blacklisted_at?->timestamp ?? 0)
                            ->sortByDesc(fn (File $f) => $f->updated_at?->timestamp ?? 0);
                    }
                    if ($sort === 'blacklisted_at_asc') {
                        return $c->sortBy(fn (File $f) => $f->blacklisted_at?->timestamp ?? 0)
                            ->sortBy(fn (File $f) => $f->updated_at?->timestamp ?? 0);
                    }
                    if ($sort === 'downloaded_at_asc') {
                        return $c->sort(function (File $a, File $b) {
                            $aDownloaded = $a->downloaded_at?->timestamp ?? 0;
                            $bDownloaded = $b->downloaded_at?->timestamp ?? 0;
                            if ($aDownloaded !== $bDownloaded) {
                                return $aDownloaded <=> $bDownloaded;
                            }

                            $aUpdated = $a->updated_at?->timestamp ?? 0;
                            $bUpdated = $b->updated_at?->timestamp ?? 0;

                            return $aUpdated <=> $bUpdated;
                        });
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

        $pagination = $applyAutoDislikedFilter($buildSearch())->paginate($limit, 'page', $page);
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
        string $blacklistType,
        string $autoDisliked,
        string $sort,
        ?int $seed,
        ?int $maxPreviewed,
        string $fileType,
        string $reactionMode,
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

        if (in_array($blacklistType, ['manual', 'auto'], true)) {
            $query->whereNotNull('blacklisted_at');

            if ($blacklistType === 'manual') {
                $query->whereNotNull('blacklist_reason')->where('blacklist_reason', '!=', '');
            } else {
                $query->where(function ($q) {
                    $q->whereNull('blacklist_reason')->orWhere('blacklist_reason', '=', '');
                });
            }
        }

        if (is_int($maxPreviewed) && $maxPreviewed >= 0) {
            $query->where('previewed_count', '<=', $maxPreviewed);
        }

        if ($fileType === 'image') {
            $query->where('mime_type', 'like', 'image/%');
        } elseif ($fileType === 'video') {
            $query->where('mime_type', 'like', 'video/%');
        } elseif ($fileType === 'audio') {
            $query->where('mime_type', 'like', 'audio/%');
        } elseif ($fileType === 'image_video') {
            $query->where(function ($q) {
                $q->where('mime_type', 'like', 'image/%')
                    ->orWhere('mime_type', 'like', 'video/%');
            });
        }

        if ($autoDisliked === 'yes') {
            $query->where('auto_disliked', true);
        } elseif ($autoDisliked === 'no') {
            $query->where('auto_disliked', false);
        }

        // Keep reaction_mode semantics aligned with Typesense path.
        if ($reactionMode === 'reacted') {
            $reactionMode = 'types';
            $reactionTypes = ['love', 'like', 'funny'];
        }

        if ($reactionMode === 'types') {
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

            $reactionTypes = is_array($reactionTypes) ? array_values(array_filter($reactionTypes, fn ($t) => in_array($t, $allTypes, true))) : null;
            if (! $reactionTypes || count($reactionTypes) === 0) {
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
        } elseif ($reactionMode === 'unreacted') {
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

            $query->whereDoesntHave('reactions', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            });
        }

        if ($sort === 'random') {
            $query->inRandomOrder();
        } elseif ($sort === 'created_at') {
            $query->orderBy('created_at', 'desc');
        } elseif ($sort === 'created_at_asc') {
            $query->orderBy('created_at', 'asc');
        } elseif ($sort === 'updated_at') {
            $query->orderBy('updated_at', 'desc');
        } elseif ($sort === 'updated_at_asc') {
            $query->orderBy('updated_at', 'asc');
        } elseif ($sort === 'blacklisted_at') {
            $query->orderBy('blacklisted_at', 'desc')->orderBy('updated_at', 'desc');
        } elseif ($sort === 'blacklisted_at_asc') {
            $query->orderBy('blacklisted_at', 'asc')->orderBy('updated_at', 'asc');
        } elseif ($sort === 'downloaded_at_asc') {
            $query->orderBy('downloaded_at', 'asc')->orderBy('updated_at', 'asc');
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
     * Fetch files ordered by the current user's reaction timestamp.
     *
     * This is intentionally DB-backed (Typesense does not have per-user reaction timestamps).
     *
     * @param  array<int, string>|null  $reactionTypes
     * @param  array<int, string>  $allTypes
     */
    protected function fetchByReactionTimestamp(
        int $page,
        int $limit,
        ?string $source,
        string $downloaded,
        string $blacklisted,
        string $blacklistType,
        ?int $maxPreviewed,
        string $autoDisliked,
        string $reactionMode,
        ?array $reactionTypes,
        array $allTypes,
        string $sort,
    ): array {
        $page = max(1, $page);
        $limit = max(1, $limit);

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

        if ($reactionMode !== 'reacted' && $reactionMode !== 'types') {
            // Sorting by reaction timestamp without a reaction scope doesn't make sense.
            // Treat as "reacted".
            $reactionMode = 'reacted';
        }

        if ($reactionMode === 'types') {
            $reactionTypes = is_array($reactionTypes) ? array_values(array_filter($reactionTypes, fn ($t) => in_array($t, $allTypes, true))) : null;
            if (! $reactionTypes || count($reactionTypes) === 0) {
                return [
                    'files' => [],
                    'metadata' => [
                        'nextCursor' => null,
                        'total' => 0,
                    ],
                ];
            }
            if (count($reactionTypes) === count($allTypes)) {
                $reactionMode = 'reacted';
            }
        }

        // Reacted == positive only.
        if ($reactionMode === 'reacted') {
            $reactionMode = 'types';
            $reactionTypes = ['love', 'like', 'funny'];
        }

        $idQuery = Reaction::query()
            ->join('files', 'files.id', '=', 'reactions.file_id')
            ->where('reactions.user_id', $userId)
            ->when($reactionMode === 'types', fn ($q) => $q->whereIn('reactions.type', $reactionTypes ?? []))
            ->when($source && $source !== 'all', fn ($q) => $q->where('files.source', $source))
            ->when($downloaded === 'yes', fn ($q) => $q->where('files.downloaded', true))
            ->when($downloaded === 'no', fn ($q) => $q->where('files.downloaded', false))
            ->when($blacklisted === 'yes', fn ($q) => $q->whereNotNull('files.blacklisted_at'))
            ->when($blacklisted === 'no', fn ($q) => $q->whereNull('files.blacklisted_at'))
            ->when($autoDisliked === 'yes', fn ($q) => $q->where('files.auto_disliked', true))
            ->when($autoDisliked === 'no', fn ($q) => $q->where('files.auto_disliked', false))
            ->when(in_array($blacklistType, ['manual', 'auto'], true), function ($q) use ($blacklistType) {
                $q->whereNotNull('files.blacklisted_at');
                if ($blacklistType === 'manual') {
                    $q->whereNotNull('files.blacklist_reason')->where('files.blacklist_reason', '!=', '');
                } else {
                    $q->where(function ($qq) {
                        $qq->whereNull('files.blacklist_reason')->orWhere('files.blacklist_reason', '=', '');
                    });
                }
            })
            ->when(is_int($maxPreviewed) && $maxPreviewed >= 0, fn ($q) => $q->where('files.previewed_count', '<=', $maxPreviewed))
            ->select('reactions.file_id')
            ->when($sort === 'reaction_at_asc', fn ($q) => $q->orderBy('reactions.created_at', 'asc'), fn ($q) => $q->orderByDesc('reactions.created_at'));

        $pagination = $idQuery->paginate($limit, ['reactions.file_id'], 'page', $page);
        $nextCursor = $pagination->hasMorePages() ? $pagination->currentPage() + 1 : null;

        $ids = collect($pagination->items())
            ->pluck('file_id')
            ->map(fn ($v) => (int) $v)
            ->values()
            ->all();

        if (empty($ids)) {
            return [
                'files' => [],
                'metadata' => [
                    'nextCursor' => null,
                    'total' => (int) $pagination->total(),
                ],
            ];
        }

        $filesById = File::query()
            ->with('metadata')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $orderedFiles = collect($ids)
            ->map(fn (int $id) => $filesById->get($id))
            ->filter()
            ->values()
            ->all();

        return [
            'files' => $orderedFiles,
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
