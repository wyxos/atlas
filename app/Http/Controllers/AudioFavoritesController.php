<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AudioFavoritesController extends Controller
{
    public function index(Request $request)
    {
        $search = [];

        // Get all audio files with love reactions for the current user using Scout
        $userId = optional($request->user())->id;

        if (! $userId) {
            return Inertia::render('audio/Index', [
                'files' => [],
                'search' => [],
                'playlistFileIds' => [],
                'query' => $request->input('query', ''),
                'playlistId' => null,
                'isSpotifyPlaylist' => false,
                'containsSpotify' => false,
            ]);
        }

        // Fetch all loved audio file IDs across all pages (Typesense per_page max is 250)
        $playlistFileIds = $this->fetchLovedAudioIds($request);

        if ($searchQuery = $request->input('query')) {
            // When a search query is present, return the ordered IDs as search results too
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
            'playlistId' => null, // This is a virtual playlist, no actual playlist ID
            'isSpotifyPlaylist' => false,
            'containsSpotify' => $containsSpotify,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->getAudioData($request));
    }

    protected function getAudioData(Request $request): array
    {
        $userId = optional($request->user())->id;

        if (! $userId) {
            return [
                'files' => [],
                'search' => [],
                'playlistFileIds' => [],
                'query' => $request->input('query', ''),
                'playlistId' => null,
                'isSpotifyPlaylist' => false,
                'containsSpotify' => false,
            ];
        }

        // Fetch all loved audio file IDs across all pages
        $playlistFileIds = $this->fetchLovedAudioIds($request);

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
            'isSpotifyPlaylist' => false,
            'containsSpotify' => $containsSpotify,
        ];
    }

    /**
     * Fetch all loved audio file IDs across all Typesense/Scout pages.
     */
    private function fetchLovedAudioIds(Request $request, int $perPage = 250): array
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
                ->where('blacklisted', false)
                ->where('not_found', false);

            $builder->whereIn('love_user_ids', [(string) $userId]);

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
