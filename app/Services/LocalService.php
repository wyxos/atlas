<?php

namespace App\Services;

use App\Models\Reaction;
use App\Services\Local\LocalBrowseQueryBuilder;
use App\Services\Local\LocalFetchParams;
use Illuminate\Support\Facades\Cache;

class LocalService extends BaseService
{
    public const string KEY = 'local';

    public const string SOURCE = 'Local';

    public const string LABEL = 'Local Files';

    public const string MODERATION_UNION_AUTO_DISLIKED_OR_BLACKLISTED_AUTO = 'auto_disliked_or_blacklisted_auto';

    public function fetch(array $params = []): array
    {
        $context = LocalFetchParams::normalize($params);
        $this->params = $context['params'];

        if ($context['shouldReturnEmpty']) {
            return LocalFetchParams::emptyResponse();
        }

        $page = $context['page'];
        $limit = $context['limit'];
        $source = $context['source'];
        $downloaded = $context['downloaded'];
        $blacklisted = $context['blacklisted'];
        $blacklistType = $context['blacklistType'];
        $sort = $context['sort'];
        $fileTypes = $context['fileTypes'];
        $seed = $context['seed'];
        $maxPreviewed = $context['maxPreviewed'];
        $reactionMode = $context['reactionMode'];
        $autoDisliked = $context['autoDisliked'];
        $moderationUnion = $context['moderationUnion'];
        $reactionTypes = $context['reactionTypes'];
        $includeTotal = $context['includeTotal'];
        $allTypes = $context['allTypes'];

        if ($sort === 'random' && (! is_int($seed) || $seed < 1)) {
            $seed = time();
            $this->params['seed'] = $seed;
        }

        if ($sort === 'reaction_at' || $sort === 'reaction_at_asc') {
            return $this->fetchByReactionTimestamp(
                page: $page,
                limit: $limit,
                source: $source,
                downloaded: $downloaded,
                blacklisted: $blacklisted,
                blacklistType: $blacklistType,
                maxPreviewed: $maxPreviewed,
                fileTypes: $fileTypes,
                autoDisliked: $autoDisliked,
                reactionMode: $reactionMode,
                reactionTypes: $reactionTypes,
                allTypes: $allTypes,
                sort: $sort,
                includeTotal: $includeTotal,
            );
        }

        if ($moderationUnion === self::MODERATION_UNION_AUTO_DISLIKED_OR_BLACKLISTED_AUTO) {
            return $this->fetchAutoDislikedOrAutoBlacklistedUsingDatabase(
                page: $page,
                limit: $limit,
                source: $source,
                downloaded: $downloaded,
                sort: $sort,
                seed: $seed,
                maxPreviewed: $maxPreviewed,
                fileTypes: $fileTypes,
            );
        }

        return $this->fetchUsingDatabase(
            page: $page,
            limit: $limit,
            source: $source,
            downloaded: $downloaded,
            blacklisted: $blacklisted,
            blacklistType: $blacklistType,
            autoDisliked: $autoDisliked,
            sort: $sort,
            seed: $seed,
            maxPreviewed: $maxPreviewed,
            fileTypes: $fileTypes,
            reactionMode: $reactionMode,
            reactionTypes: $reactionTypes,
            allTypes: $allTypes,
        );
    }

    /**
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
        array $fileTypes,
        string $reactionMode,
        ?array $reactionTypes,
        array $allTypes,
    ): array {
        $page = max(1, $page);
        $limit = max(1, $limit);
        $userId = auth()->id();

        $query = LocalBrowseQueryBuilder::buildBaseQuery(
            source: $source,
            downloaded: $downloaded,
            blacklisted: $blacklisted,
            blacklistType: $blacklistType,
            maxPreviewed: $maxPreviewed,
            fileTypes: $fileTypes,
        );

        LocalBrowseQueryBuilder::applyAutoDislikedFilter($query, $autoDisliked);

        if ($reactionMode === 'types') {
            if (! $userId) {
                return [
                    'files' => [],
                    'metadata' => [
                        'nextCursor' => null,
                        'total' => 0,
                    ],
                ];
            }

            $reactionTypes = is_array($reactionTypes)
                ? array_values(array_filter($reactionTypes, fn ($type) => in_array($type, $allTypes, true)))
                : null;

            if (! $reactionTypes || count($reactionTypes) === 0) {
                return [
                    'files' => [],
                    'metadata' => [
                        'nextCursor' => null,
                        'total' => 0,
                    ],
                ];
            }

            $query->whereHas('reactions', function ($builder) use ($userId, $reactionTypes): void {
                $builder->where('user_id', $userId)
                    ->whereIn('type', $reactionTypes);
            });
        } elseif ($reactionMode === 'unreacted') {
            if (! $userId) {
                return [
                    'files' => [],
                    'metadata' => [
                        'nextCursor' => null,
                        'total' => 0,
                    ],
                ];
            }

            $query->whereDoesntHave('reactions', function ($builder) use ($userId): void {
                $builder->where('user_id', $userId);
            });
        } elseif ($reactionMode === 'reacted') {
            if (! $userId) {
                return [
                    'files' => [],
                    'metadata' => [
                        'nextCursor' => null,
                        'total' => 0,
                    ],
                ];
            }

            $query->whereHas('reactions', function ($builder) use ($userId): void {
                $builder->where('user_id', $userId)
                    ->whereIn('type', ['love', 'like', 'funny']);
            });
        }

        if ($sort === 'random') {
            $pagination = LocalBrowseQueryBuilder::paginateRandomIds($query, $page, $limit, $seed ?? time());
        } else {
            LocalBrowseQueryBuilder::applyStandardSort($query, $sort);
            $pagination = LocalBrowseQueryBuilder::paginateIds($query, $page, $limit);
        }

        return [
            'files' => LocalBrowseQueryBuilder::hydrateFiles($pagination['ids']),
            'metadata' => [
                'nextCursor' => $pagination['nextCursor'],
                'total' => $pagination['total'],
            ],
        ];
    }

    protected function fetchAutoDislikedOrAutoBlacklistedUsingDatabase(
        int $page,
        int $limit,
        ?string $source,
        string $downloaded,
        string $sort,
        ?int $seed,
        ?int $maxPreviewed,
        array $fileTypes,
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

        $effectiveSort = in_array($sort, ['reaction_at', 'reaction_at_asc'], true) ? 'blacklisted_at' : $sort;

        $query = LocalBrowseQueryBuilder::buildBaseQuery(
            source: $source,
            downloaded: $downloaded,
            blacklisted: 'any',
            blacklistType: 'any',
            maxPreviewed: $maxPreviewed,
            fileTypes: $fileTypes,
        );

        $query->where(function ($builder) use ($userId): void {
            $builder->where(function ($autoDislikedQuery) use ($userId): void {
                $autoDislikedQuery->where('auto_disliked', true)
                    ->whereHas('reactions', function ($reactionQuery) use ($userId): void {
                        $reactionQuery->where('user_id', $userId)
                            ->where('type', 'dislike');
                    });
            })->orWhere(function ($blacklistedQuery): void {
                $blacklistedQuery->whereNotNull('blacklisted_at')
                    ->where(function ($blacklistTypeQuery): void {
                        $blacklistTypeQuery->whereNull('blacklist_reason')
                            ->orWhere('blacklist_reason', '=', '');
                    });
            });
        });

        if ($effectiveSort === 'random') {
            $pagination = LocalBrowseQueryBuilder::paginateRandomIds($query, $page, $limit, $seed ?? time());
        } else {
            LocalBrowseQueryBuilder::applyStandardSort($query, $effectiveSort);
            $pagination = LocalBrowseQueryBuilder::paginateIds($query, $page, $limit);
        }

        return [
            'files' => LocalBrowseQueryBuilder::hydrateFiles($pagination['ids']),
            'metadata' => [
                'nextCursor' => $pagination['nextCursor'],
                'total' => $pagination['total'],
            ],
        ];
    }

    /**
     * Fetch files ordered by the current user's reaction timestamp.
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
        array $fileTypes,
        string $autoDisliked,
        string $reactionMode,
        ?array $reactionTypes,
        array $allTypes,
        string $sort,
        bool $includeTotal = false,
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
            $reactionMode = 'reacted';
        }

        if ($reactionMode === 'types') {
            $reactionTypes = is_array($reactionTypes)
                ? array_values(array_filter($reactionTypes, fn ($type) => in_array($type, $allTypes, true)))
                : null;

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

        if ($reactionMode === 'reacted') {
            $reactionMode = 'types';
            $reactionTypes = ['love', 'like', 'funny'];
        }

        $idQuery = Reaction::query()
            ->join('files', 'files.id', '=', 'reactions.file_id')
            ->where('reactions.user_id', $userId)
            ->when($reactionMode === 'types', fn ($builder) => $builder->whereIn('reactions.type', $reactionTypes ?? []))
            ->when($source && $source !== 'all', fn ($builder) => $builder->where('files.source', $source))
            ->when($downloaded === 'yes', fn ($builder) => $builder->where('files.downloaded', true))
            ->when($downloaded === 'no', fn ($builder) => $builder->where('files.downloaded', false))
            ->when($blacklisted === 'yes', fn ($builder) => $builder->whereNotNull('files.blacklisted_at'))
            ->when($blacklisted === 'no', fn ($builder) => $builder->whereNull('files.blacklisted_at'))
            ->when($autoDisliked === 'yes', fn ($builder) => $builder->where('files.auto_disliked', true))
            ->when($autoDisliked === 'no', fn ($builder) => $builder->where('files.auto_disliked', false))
            ->when(in_array($blacklistType, ['manual', 'auto'], true), function ($builder) use ($blacklistType): void {
                $builder->whereNotNull('files.blacklisted_at');

                if ($blacklistType === 'manual') {
                    $builder->whereNotNull('files.blacklist_reason')->where('files.blacklist_reason', '!=', '');

                    return;
                }

                $builder->where(function ($blacklistBuilder): void {
                    $blacklistBuilder->whereNull('files.blacklist_reason')
                        ->orWhere('files.blacklist_reason', '=', '');
                });
            })
            ->when(is_int($maxPreviewed) && $maxPreviewed >= 0, fn ($builder) => $builder->where('files.previewed_count', '<=', $maxPreviewed))
            ->when(! in_array('all', $fileTypes, true), function ($builder) use ($fileTypes): void {
                $builder->where(function ($mimeBuilder) use ($fileTypes): void {
                    $hasClause = false;

                    if (in_array('image', $fileTypes, true)) {
                        $mimeBuilder->orWhere('files.mime_type', 'like', 'image/%');
                        $hasClause = true;
                    }

                    if (in_array('video', $fileTypes, true)) {
                        $mimeBuilder->orWhere('files.mime_type', 'like', 'video/%');
                        $hasClause = true;
                    }

                    if (in_array('audio', $fileTypes, true)) {
                        $mimeBuilder->orWhere('files.mime_type', 'like', 'audio/%');
                        $hasClause = true;
                    }

                    if (in_array('other', $fileTypes, true)) {
                        $mimeBuilder->orWhere(function ($otherBuilder): void {
                            $otherBuilder->whereNull('files.mime_type')
                                ->orWhere('files.mime_type', '=', '')
                                ->orWhere(function ($nonMediaBuilder): void {
                                    $nonMediaBuilder->where('files.mime_type', 'not like', 'image/%')
                                        ->where('files.mime_type', 'not like', 'video/%')
                                        ->where('files.mime_type', 'not like', 'audio/%');
                                });
                        });
                        $hasClause = true;
                    }

                    if (! $hasClause) {
                        $mimeBuilder->orWhereRaw('1=1');
                    }
                });
            })
            ->select('reactions.file_id')
            ->when(
                $sort === 'reaction_at_asc',
                fn ($builder) => $builder->orderBy('reactions.created_at', 'asc'),
                fn ($builder) => $builder->orderByDesc('reactions.created_at')
            );

        $total = null;
        if ($includeTotal) {
            $hash = sha1(json_encode([
                'user_id' => $userId,
                'reaction_mode' => $reactionMode,
                'reaction_types' => $reactionTypes,
                'source' => $source,
                'downloaded' => $downloaded,
                'blacklisted' => $blacklisted,
                'blacklist_type' => $blacklistType,
                'auto_disliked' => $autoDisliked,
                'max_previewed' => $maxPreviewed,
                'file_types' => $fileTypes,
            ]));

            $cacheKey = "local:reaction_at_total:{$hash}";
            $total = Cache::remember($cacheKey, now()->addMinutes(5), fn () => (int) (clone $idQuery)->reorder()->count());
        }

        $pagination = $idQuery->simplePaginate($limit, ['reactions.file_id'], 'page', $page);
        $nextCursor = $pagination->hasMorePages() ? $pagination->currentPage() + 1 : null;

        $ids = collect($pagination->items())
            ->pluck('file_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        if ($ids === []) {
            return [
                'files' => [],
                'metadata' => [
                    'nextCursor' => null,
                    'total' => $total,
                ],
            ];
        }

        return [
            'files' => LocalBrowseQueryBuilder::hydrateFiles($ids),
            'metadata' => [
                'nextCursor' => $nextCursor,
                'total' => $total,
            ],
        ];
    }

    public function transform(array $response, array $params = []): array
    {
        $files = $response['files'] ?? [];
        $nextCursor = $response['metadata']['nextCursor'] ?? null;
        $total = $response['metadata']['total'] ?? null;
        $total = is_numeric($total) ? (int) $total : null;

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

    public function defaultParams(): array
    {
        return [
            'limit' => 20,
            'source' => 'all',
        ];
    }
}
