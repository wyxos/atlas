<?php

namespace App\Http\Middleware;

use App\Models\File;
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

        $hasSpotifyFiles = false;
        if ($user = $request->user()) {
            $userId = $user->id;
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
            'playlists' => null,
            'hasSpotifyFiles' => $hasSpotifyFiles,
        ];
    }
}
