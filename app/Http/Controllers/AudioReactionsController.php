<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AudioReactionsController extends Controller
{
    /**
     * Map of reaction types to their respective user ID fields in the search index.
     */
    private const REACTION_FIELDS = [
        'all' => null,
        'favorites' => 'love_user_ids',
        'liked' => 'like_user_ids',
        'funny' => 'funny_user_ids',
        'disliked' => 'dislike_user_ids',
        'missing' => null,
        'unrated' => null,
        'spotify' => null,
    ];

    public function index(Request $request, string $type = 'all')
    {
        if (! array_key_exists($type, self::REACTION_FIELDS)) {
            abort(404, 'Invalid reaction type');
        }

        $userId = optional($request->user())->id;

        if (! $userId) {
            return Inertia::render('audio/Index', [
                'files' => [],
                'search' => [],
                'playlistFileIds' => [],
                'query' => $request->input('query', ''),
                'playlistId' => null,
                'reactionType' => $type,
                'isSpotifyPlaylist' => false,
                'containsSpotify' => false,
            ]);
        }

        // Fetch file IDs based on reaction type
        $playlistFileIds = $this->fetchAudioIdsByReaction($request, $type);

        $search = [];
        if ($searchQuery = $request->input('query')) {
            $search = collect($playlistFileIds)
                ->map(fn ($id) => ['id' => (int) $id])
                ->values()
                ->all();
        }

        $files = array_map(fn ($id) => ['id' => $id], $playlistFileIds);

        // Check if any files are from Spotify
        $containsSpotify = (bool) File::query()
            ->whereIn('id', $playlistFileIds)
            ->where(function ($q) {
                $q->where('source', 'spotify')
                    ->orWhere('mime_type', 'audio/spotify')
                    ->orWhere('listing_metadata->source', 'spotify');
            })
            ->exists();

        return Inertia::render('audio/Index', [
            'files' => $files,
            'search' => $search,
            'playlistFileIds' => $playlistFileIds,
            'query' => $request->input('query', ''),
            'playlistId' => null,
            'reactionType' => $type,
            'isSpotifyPlaylist' => false,
            'containsSpotify' => $containsSpotify,
        ]);
    }

    public function data(Request $request, string $type = 'all'): JsonResponse
    {
        if (! array_key_exists($type, self::REACTION_FIELDS)) {
            abort(404, 'Invalid reaction type');
        }

        return response()->json($this->getAudioData($request, $type));
    }

    protected function getAudioData(Request $request, string $type): array
    {
        $userId = optional($request->user())->id;

        if (! $userId) {
            return [
                'files' => [],
                'search' => [],
                'playlistFileIds' => [],
                'query' => $request->input('query', ''),
                'playlistId' => null,
                'reactionType' => $type,
                'isSpotifyPlaylist' => false,
                'containsSpotify' => false,
            ];
        }

        $playlistFileIds = $this->fetchAudioIdsByReaction($request, $type);

        $search = [];
        if ($searchQuery = $request->input('query')) {
            $search = collect($playlistFileIds)
                ->map(fn ($id) => ['id' => (int) $id])
                ->values()
                ->all();
        }

        $files = array_map(fn ($id) => ['id' => $id], $playlistFileIds);

        // Check if any files are from Spotify
        $containsSpotify = (bool) File::query()
            ->whereIn('id', $playlistFileIds)
            ->where(function ($q) {
                $q->where('source', 'spotify')
                    ->orWhere('mime_type', 'audio/spotify')
                    ->orWhere('listing_metadata->source', 'spotify');
            })
            ->exists();

        return [
            'files' => $files,
            'search' => $search,
            'playlistFileIds' => $playlistFileIds,
            'query' => $request->input('query', ''),
            'playlistId' => null,
            'reactionType' => $type,
            'isSpotifyPlaylist' => false,
            'containsSpotify' => $containsSpotify,
        ];
    }

    /**
     * Fetch audio file IDs based on the reaction type.
     */
    private function fetchAudioIdsByReaction(Request $request, string $type, int $perPage = 250): array
    {
        $userId = optional($request->user())->id;
        if (! $userId) {
            return [];
        }

        $page = 1;
        $ids = [];
        $lastPage = 1;

        do {
            // Rebuild the query for each page to avoid state carry-over
            $builder = File::search($request->input('query', '*'))
                ->where('mime_group', 'audio')
                ->where('blacklisted', false);

            // Apply type-specific filters
            if ($type === 'all') {
                // All songs - no additional reaction filter, just exclude not_found
                $builder->where('not_found', false);
            } elseif ($type === 'missing') {
                // Missing files - those flagged as not found
                $builder->where('not_found', true);
            } elseif ($type === 'unrated') {
                // Unrated - files with no reactions from this user
                // We need to find files where the user ID is NOT in reacted_user_ids
                $builder->where('not_found', false);
                // Use whereNotIn to exclude files where this user has reacted
                $builder->whereNotIn('reacted_user_ids', [(string) $userId]);
            } elseif ($type === 'spotify') {
                // Spotify - files from Spotify source
                $builder->where('not_found', false)
                    ->where('source', 'spotify');
            } else {
                // Specific reaction type (favorites, liked, funny, disliked)
                $builder->where('not_found', false);
                $field = self::REACTION_FIELDS[$type];
                if ($field) {
                    $builder->whereIn($field, [(string) $userId]);
                }
            }

            // Paginate this page explicitly
            $paginator = $builder->paginate($perPage, 'page', $page);

            // Collect IDs from this page
            $pageIds = collect($paginator->items())
                ->map(fn ($file) => (int) $file->id)
                ->values()
                ->all();

            $ids = array_merge($ids, $pageIds);
            $lastPage = (int) $paginator->lastPage();
            $page++;
        } while ($page <= $lastPage);

        // Ensure unique & preserve order of discovery
        return collect($ids)->unique()->values()->all();
    }
}
