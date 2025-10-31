<?php

namespace App\Http\Middleware;

use App\Models\File;
use App\Models\Playlist;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        $playlists = null;
        $hasSpotifyFiles = false;
        if ($user = $request->user()) {
            $userId = $user->id;
            $rows = Playlist::query()
                ->where('user_id', $userId)
                ->orderBy('is_smart')
                ->orderBy('name')
                ->get(['id', 'name', 'is_smart', 'is_system', 'smart_parameters']);

            $playlists = [
                'allSongsId' => Playlist::query()->where('user_id', $userId)->where('name', 'All songs')->value('id'),
                'likedId' => Playlist::query()->where('user_id', $userId)->where('name', 'Liked')->value('id'),
                'favoritesId' => Playlist::query()->where('user_id', $userId)->where('name', 'Favorites')->value('id'),
                'dislikedId' => Playlist::query()->where('user_id', $userId)->where('name', 'Disliked')->value('id'),
                'funnyId' => Playlist::query()->where('user_id', $userId)->where('name', 'Funny')->value('id'),
                'unratedId' => Playlist::query()->where('user_id', $userId)->where('name', 'Unrated')->value('id'),
                'list' => $rows->map(function ($p) {
                    $reaction = null;
                    $params = $p->smart_parameters;
                    if (is_string($params)) {
                        $decoded = json_decode($params, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $params = $decoded;
                        } else {
                            $params = [];
                        }
                    }
                    if (is_array($params) && array_key_exists('reaction', $params)) {
                        $reaction = $params['reaction'];
                    }

                    return [
                        'id' => (int) $p->id,
                        'name' => (string) $p->name,
                        'is_smart' => (bool) $p->is_smart,
                        'is_system' => (bool) $p->is_system,
                        'reaction' => $reaction,
                    ];
                })->values()->all(),
            ];

            // Check if there are any Spotify files (cached for performance on large tables)
            $hasSpotifyFiles = \Illuminate\Support\Facades\Cache::remember(
                "has_spotify_files:{$userId}",
                now()->addMinutes(10),
                fn () => File::where('source', 'spotify')
                    ->where('mime_type', 'like', 'audio/%')
                    ->exists()
            );
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'playlists' => $playlists,
            'hasSpotifyFiles' => $hasSpotifyFiles,
        ];
    }
}
