<?php

namespace App\Services;

use App\Models\File;

class LocalService extends BaseService
{
    public const string KEY = 'local';

    public const string SOURCE = 'Local';

    public const string LABEL = 'Local Files';

    /**
     * Fetch local files from database (will use Scout when installed).
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

        // Build query
        $query = File::query();

        // Filter by source if provided and not 'all'
        if ($source && $source !== 'all') {
            $query->where('source', $source);
        }

        // Filter by downloaded tri-state
        if ($downloaded === 'yes') {
            $query->where('downloaded', true);
        } elseif ($downloaded === 'no') {
            $query->where(function ($q) {
                $q->whereNull('downloaded')->orWhere('downloaded', false);
            });
        }

        // Filter by blacklisted tri-state
        if ($blacklisted === 'yes') {
            $query->whereNotNull('blacklisted_at');
        } elseif ($blacklisted === 'no') {
            $query->whereNull('blacklisted_at');
        }

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

            // When all reaction types are selected (default), treat this as "no filter".
            if (count($reactionTypes) === 0) {
                $query->whereRaw('1 = 0');
            } elseif (count($reactionTypes) < count($allTypes)) {
                $userId = auth()->id();
                if (! $userId) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereExists(function ($q) use ($userId, $reactionTypes) {
                        $q->selectRaw('1')
                            ->from('reactions')
                            ->whereColumn('reactions.file_id', 'files.id')
                            ->where('reactions.user_id', $userId)
                            ->whereIn('reactions.type', $reactionTypes);
                    });
                }
            }
        }

        // Order by most recently downloaded/updated
        $query->orderBy('downloaded_at', 'desc')
            ->orderBy('updated_at', 'desc');

        // Get total count for pagination
        $total = $query->count();
        $totalPages = (int) ceil($total / $limit);

        // Paginate with eager loaded metadata (needed for moderation)
        $files = $query->with('metadata')
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        // Determine next cursor (page number)
        $nextCursor = $page < $totalPages ? $page + 1 : null;

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
        $file = $fileId ? File::with('metadata')->find($fileId) : null;

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
