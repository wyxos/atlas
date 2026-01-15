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
        $reaction = $params['reaction'] ?? null;

        // Filter by current user's reaction types (optional)
        $allTypes = ['love', 'like', 'dislike', 'funny'];
        $reactionTypes = null;
        if (is_array($reaction)) {
            $reactionTypes = array_values(array_unique(array_filter(array_map(fn ($v) => is_string($v) ? $v : (is_numeric($v) ? (string) $v : ''), $reaction))));
        } elseif (is_string($reaction) && $reaction !== '') {
            $reactionTypes = [$reaction];
        }

        $buildSearch = function () use ($params, $source, $downloaded, $blacklisted) {
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

            // Order by most recently downloaded/updated
            $builder->orderBy('downloaded_at', 'desc')
                ->orderBy('updated_at', 'desc');

            // Paginate with eager loaded metadata (needed for moderation)
            $builder->query(fn ($query) => $query->with('metadata'));

            return $builder;
        };

        if ($reactionTypes !== null) {
            $reactionTypes = array_values(array_filter($reactionTypes, fn ($t) => in_array($t, $allTypes, true)));

            // When all reaction types are selected (default), treat this as "no filter".
            if (count($reactionTypes) === 0) {
                return [
                    'files' => [],
                    'metadata' => [
                        'nextCursor' => null,
                    ],
                ];
            }

            if (count($reactionTypes) < count($allTypes)) {
                $userId = auth()->id();
                if (! $userId) {
                    return [
                        'files' => [],
                        'metadata' => [
                            'nextCursor' => null,
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

                    return [
                        'files' => $files->all(),
                        'metadata' => [
                            'nextCursor' => $nextCursor,
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
                    ->sort(function (File $a, File $b) {
                        $aDownloaded = $a->downloaded_at?->timestamp ?? 0;
                        $bDownloaded = $b->downloaded_at?->timestamp ?? 0;
                        if ($aDownloaded !== $bDownloaded) {
                            return $bDownloaded <=> $aDownloaded;
                        }

                        $aUpdated = $a->updated_at?->timestamp ?? 0;
                        $bUpdated = $b->updated_at?->timestamp ?? 0;

                        return $bUpdated <=> $aUpdated;
                    })
                    ->values();

                $totalPages = (int) ceil($total / $limit);
                $filesPage = $files->slice(($page - 1) * $limit, $limit)->values();
                $nextCursor = $page < $totalPages ? $page + 1 : null;

                return [
                    'files' => $filesPage->all(),
                    'metadata' => [
                        'nextCursor' => $nextCursor,
                    ],
                ];
            }
        }

        $pagination = $buildSearch()->paginate($limit, 'page', $page);
        $files = collect($pagination->items());
        $nextCursor = $pagination->hasMorePages() ? $pagination->currentPage() + 1 : null;

        // Return files directly - Browser.php will use FileItemFormatter
        return [
            'files' => $files->all(), // Return File models directly
            'metadata' => [
                'nextCursor' => $nextCursor,
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

        // For local mode, files are already File models, so return them directly
        // Browser.php will handle formatting with FileItemFormatter
        return [
            'files' => $files,
            'filter' => [
                ...$this->params,
                'next' => $nextCursor,
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
