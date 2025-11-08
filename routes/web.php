<?php

use App\Events\DemoPing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    if (request()->boolean('demo')) {
        broadcast(new DemoPing('Demo event at '.now()->toDateTimeString()));
        DemoPing::broadcast('Reverb OK at '.now()->toDateTimeString());
    }

    return Inertia::render('Welcome');
})->name('home');

if (app()->environment('testing')) {
    Route::post('testing/reverb-demo', function () {
        DemoPing::broadcast('Realtime test ping');

        return response()->json(['ok' => true]);
    })->name('testing.reverb-demo');
}

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('storage/atlas-app/{path}', function (Request $request, string $path) {
        $normalized = ltrim($path, '/');
        $disk = \Illuminate\Support\Facades\Storage::disk('atlas_app');

        if (! $disk->exists($normalized)) {
            abort(404);
        }

        $absolute = $disk->path($normalized);
        if (! is_file($absolute)) {
            abort(404);
        }

        $mimeType = \Illuminate\Support\Facades\File::mimeType($absolute) ?: 'application/octet-stream';

        return response()->file($absolute, [
            'Content-Type' => $mimeType,
        ]);
    })->where('path', '.*')->name('storage.atlas_app');
    Route::get('dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    // Photos (all local images)
    Route::get('photos', [App\Http\Controllers\PhotosController::class, 'index'])->name('photos');
    Route::get('photos/data', [App\Http\Controllers\PhotosController::class, 'data'])->name('photos.data');

    // Photos > Reactions (per-user favorites/liked/funny/disliked)
    Route::get('photos/reactions/{kind}', [App\Http\Controllers\PhotosReactionsController::class, 'index'])
        ->whereIn('kind', ['favorites', 'liked', 'funny', 'disliked'])
        ->name('photos.reactions.index');
    Route::get('photos/reactions/{kind}/data', [App\Http\Controllers\PhotosReactionsController::class, 'data'])
        ->whereIn('kind', ['favorites', 'liked', 'funny', 'disliked'])
        ->name('photos.reactions.data');

    // Photos > Disliked categories
    Route::get('photos/disliked/{category}', [App\Http\Controllers\PhotosDislikedController::class, 'index'])
        ->whereIn('category', ['all', 'manual', 'ignored', 'auto', 'not-disliked'])
        ->name('photos.disliked');
    Route::get('photos/disliked/{category}/data', [App\Http\Controllers\PhotosDislikedController::class, 'data'])
        ->whereIn('category', ['all', 'manual', 'ignored', 'auto', 'not-disliked'])
        ->name('photos.disliked.data');

    // Photos > Unrated (no reaction yet)
    Route::get('photos/unrated', [App\Http\Controllers\PhotosUnratedController::class, 'index'])
        ->name('photos.unrated');
    Route::get('photos/unrated/data', [App\Http\Controllers\PhotosUnratedController::class, 'data'])
        ->name('photos.unrated.data');

    // Photos > Unpreviewed (previewed_count = 0 and no reactions)
    Route::get('photos/unpreviewed', [App\Http\Controllers\PhotosUnpreviewedController::class, 'index'])
        ->name('photos.unpreviewed');
    Route::get('photos/unpreviewed/data', [App\Http\Controllers\PhotosUnpreviewedController::class, 'data'])
        ->name('photos.unpreviewed.data');

    // Photos > Unseen (viewed_count = 0)
    Route::get('photos/unseen', [App\Http\Controllers\PhotosUnseenController::class, 'index'])
        ->name('photos.unseen');
    Route::get('photos/unseen/data', [App\Http\Controllers\PhotosUnseenController::class, 'data'])
        ->name('photos.unseen.data');

    // Photos > Not Found
    Route::get('photos/not-found', [App\Http\Controllers\PhotosNotFoundController::class, 'index'])
        ->name('photos.not-found');
    Route::get('photos/not-found/data', [App\Http\Controllers\PhotosNotFoundController::class, 'data'])
        ->name('photos.not-found.data');

    Route::get('reels', [App\Http\Controllers\ReelsController::class, 'index'])->name('reels');
    Route::get('reels/data', [App\Http\Controllers\ReelsController::class, 'data'])->name('reels.data');

    // Reels > Reactions (per-user favorites/liked/funny/disliked)
    Route::get('reels/reactions/{kind}', [App\Http\Controllers\ReelsReactionsController::class, 'index'])
        ->whereIn('kind', ['favorites', 'liked', 'funny', 'disliked'])
        ->name('reels.reactions.index');
    Route::get('reels/reactions/{kind}/data', [App\Http\Controllers\ReelsReactionsController::class, 'data'])
        ->whereIn('kind', ['favorites', 'liked', 'funny', 'disliked'])
        ->name('reels.reactions.data');

    // Reels > Unrated (no reaction yet)
    Route::get('reels/unrated', [App\Http\Controllers\ReelsUnratedController::class, 'index'])
        ->name('reels.unrated');
    Route::get('reels/unrated/data', [App\Http\Controllers\ReelsUnratedController::class, 'data'])
        ->name('reels.unrated.data');

    // Reels > Disliked categories
    Route::get('reels/disliked/{category}', [App\Http\Controllers\ReelsDislikedController::class, 'index'])
        ->whereIn('category', ['all', 'manual', 'ignored', 'auto', 'not-disliked'])
        ->name('reels.disliked');
    Route::get('reels/disliked/{category}/data', [App\Http\Controllers\ReelsDislikedController::class, 'data'])
        ->whereIn('category', ['all', 'manual', 'ignored', 'auto', 'not-disliked'])
        ->name('reels.disliked.data');

    // Reels > Not Found
    Route::get('reels/not-found', [App\Http\Controllers\ReelsNotFoundController::class, 'index'])
        ->name('reels.not-found');
    Route::get('reels/not-found/data', [App\Http\Controllers\ReelsNotFoundController::class, 'data'])
        ->name('reels.not-found.data');

    // Browse page
    Route::get('browse', [App\Http\Controllers\BrowseController::class, 'index'])
        ->name('browse');
    Route::get('browse/data', [App\Http\Controllers\BrowseController::class, 'data'])
        ->name('browse.data');

    // Users list (super admin only, enforced in controller)
    Route::get('users', [App\Http\Controllers\UserController::class, 'index'])
        ->name('users');

    // Files list (auth users)
    Route::get('files', [App\Http\Controllers\FileController::class, 'index'])
        ->name('files');
    Route::get('files/mime-types', [App\Http\Controllers\FileController::class, 'mimeTypes'])
        ->name('files.mime-types');

    // View URL for files (auth required)
    Route::get('files/{file}/view', [App\Http\Controllers\FileController::class, 'view'])
        ->name('files.view');

    // Proxy originals for hotlink-protected sources (host whitelist inside controller)
    Route::get('files/{file}/remote', [App\Http\Controllers\FileController::class, 'remote'])
        ->name('files.remote');

    // Delete file (auth users)
    Route::delete('files/{file}', [App\Http\Controllers\FileController::class, 'destroy'])
        ->name('files.destroy');
    // Purge local file path + downloaded flags (auth users)
    Route::post('files/{file}/purge-local', [App\Http\Controllers\FileController::class, 'purgeLocal'])
        ->name('files.purge-local');

    // File reactions (placeholder endpoint)
    Route::post('files/{file}/react', [App\Http\Controllers\FileController::class, 'react'])
        ->name('files.react');

    // Browse-specific reactions
    Route::post('browse/files/{file}/react', [App\Http\Controllers\BrowseController::class, 'react'])
        ->name('browse.files.react');
    Route::post('browse/files/{file}/dislike-blacklist', [App\Http\Controllers\BrowseController::class, 'dislikeBlacklist'])
        ->name('browse.files.dislike-blacklist');
    Route::post('browse/files/{file}/unblacklist', [App\Http\Controllers\BrowseController::class, 'unblacklist'])
        ->name('browse.files.unblacklist');

    // Moderation rules (JSON CRUD)
    Route::get('moderation/rules', [App\Http\Controllers\ModerationRuleController::class, 'index'])->name('moderation.rules.index');
    Route::post('moderation/rules', [App\Http\Controllers\ModerationRuleController::class, 'store'])->name('moderation.rules.store');
    Route::put('moderation/rules/{rule}', [App\Http\Controllers\ModerationRuleController::class, 'update'])->name('moderation.rules.update');
    Route::delete('moderation/rules/{rule}', [App\Http\Controllers\ModerationRuleController::class, 'destroy'])->name('moderation.rules.destroy');

    Route::post('browse/files/{file}/react-download', [App\Http\Controllers\BrowseController::class, 'reactDownload'])
        ->name('browse.files.react-download');

    // Generic batch react by ids: type in love|like|funny|dislike
    Route::post('browse/files/batch-react', [App\Http\Controllers\BrowseController::class, 'batchReact'])
        ->name('browse.files.batch-react');
    Route::post('browse/files/batch-unblacklist', [App\Http\Controllers\BrowseController::class, 'batchUnblacklist'])
        ->name('browse.files.batch-unblacklist');

    // Browse-specific seen flags
    Route::post('browse/files/{file}/preview-seen', [App\Http\Controllers\BrowseController::class, 'previewSeen'])
        ->name('browse.files.preview-seen');
    Route::post('browse/files/{file}/file-seen', [App\Http\Controllers\BrowseController::class, 'fileSeen'])
        ->name('browse.files.file-seen');
    // Report missing/404 media
    Route::post('browse/files/{file}/resolve-media', \App\Http\Controllers\ResolveCivitaiMediaController::class)
        ->name('browse.files.resolve-media');
    Route::post('browse/files/{file}/report-missing', [App\Http\Controllers\BrowseController::class, 'reportMissing'])
        ->name('browse.files.report-missing');
    Route::post('browse/files/{file}/clear-not-found', [App\Http\Controllers\BrowseController::class, 'clearNotFound'])
        ->name('browse.files.clear-not-found');

    // Audio endpoints (non-page)
    Route::get('audio/{file}/details', [App\Http\Controllers\AudioController::class, 'details'])
        ->name('audio.details');

    // Downloads
    Route::get('downloads', [App\Http\Controllers\DownloadsController::class, 'index'])
        ->name('downloads');
    Route::post('downloads/{download}/pause', [App\Http\Controllers\DownloadsController::class, 'pause'])
        ->name('downloads.pause');
    Route::post('downloads/{download}/resume', [App\Http\Controllers\DownloadsController::class, 'resume'])
        ->name('downloads.resume');
    Route::post('downloads/{download}/cancel', [App\Http\Controllers\DownloadsController::class, 'cancel'])
        ->name('downloads.cancel');
    Route::post('downloads/{download}/retry', [App\Http\Controllers\DownloadsController::class, 'retry'])
        ->name('downloads.retry');
    Route::delete('downloads/{download}', [App\Http\Controllers\DownloadsController::class, 'destroy'])
        ->name('downloads.destroy');
    Route::delete('downloads/{download}/with-file', [App\Http\Controllers\DownloadsController::class, 'destroyWithFile'])
        ->name('downloads.destroy-with-file');
    Route::post('audio/batch-details', [App\Http\Controllers\AudioController::class, 'batchDetails'])
        ->name('audio.batch-details');
    Route::get('audio/stream/{file}', [App\Http\Controllers\AudioController::class, 'stream'])
        ->name('audio.stream');
    // Global player context
    Route::post('audio/activate-playlist', [App\Http\Controllers\AudioController::class, 'activatePlaylist'])
        ->name('audio.activate-playlist');
    // Reactions
    Route::post('audio/{file}/react', [App\Http\Controllers\AudioController::class, 'react'])
        ->name('audio.react');

    // Spotify authentication and token proxy
    Route::get('spotify/connect', [App\Http\Controllers\SpotifyAuthController::class, 'connect'])
        ->name('spotify.connect');
    Route::get('spotify/callback', [App\Http\Controllers\SpotifyAuthController::class, 'callback'])
        ->name('spotify.callback');
    Route::get('spotify/token', [App\Http\Controllers\SpotifyAuthController::class, 'token'])
        ->name('spotify.token');
    Route::post('spotify/disconnect', [App\Http\Controllers\SpotifyAuthController::class, 'disconnect'])
        ->name('spotify.disconnect');

    // Audio reaction-based virtual playlists (all songs, favorites, liked, funny, disliked, missing, unrated, spotify)
    Route::get('audio/{type}', [App\Http\Controllers\AudioReactionsController::class, 'index'])
        ->where('type', 'all|favorites|liked|funny|disliked|missing|unrated|spotify')
        ->name('audio.reactions');
    Route::get('audio/{type}/data', [App\Http\Controllers\AudioReactionsController::class, 'data'])
        ->where('type', 'all|favorites|liked|funny|disliked|missing|unrated|spotify')
        ->name('audio.reactions.data');

    // New playlists route (playlists/{playlistId})
    Route::get('playlists/{playlist}', [App\Http\Controllers\AudioController::class, 'playlist'])
        ->name('playlists.show');
    Route::get('playlists/{playlist}/ids', [App\Http\Controllers\AudioController::class, 'playlistIds'])
        ->name('playlists.ids');

    Route::get('dashboard/file-stats', [App\Http\Controllers\DashboardController::class, 'getFileStatsJson'])
        ->name('dashboard.stats');
    Route::post('dashboard/clear-cache', [App\Http\Controllers\DashboardController::class, 'clearCache'])
        ->name('dashboard.cache');
});

// Local-only developer route: impersonate by email, then redirect to browse
if (app()->environment('local')) {
    Route::get('dev/impersonate', function (Request $request) {
        $email = (string) $request->query('email', '');
        if ($email === '') {
            return response('Missing email', 400);
        }
        $user = \App\Models\User::where('email', $email)->first();
        if (! $user) {
            return response('User not found', 404);
        }
        Auth::login($user, true);

        return redirect()->intended(route('browse'));
    })->name('dev.impersonate');
}

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
require __DIR__.'/playground.php';
