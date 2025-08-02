<?php

use App\Http\Controllers\AudioController;
use App\Http\Controllers\BrowseController;
use App\Http\Controllers\CivitaiTestController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\ImageController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::group(['middleware' => ['auth', 'verified']], function () {
    Route::get('dashboard', [App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard');
    Route::get('dashboard/file-stats', [App\Http\Controllers\DashboardController::class, 'getFileStatsJson'])->name('dashboard.file-stats');
    Route::post('dashboard/clear-cache', [App\Http\Controllers\DashboardController::class, 'clearCache'])->name('dashboard.clear-cache');

    // Browse routes
    Route::get('browse', [BrowseController::class, 'index'])->name('browse');
    Route::get('browse/data', [BrowseController::class, 'data'])->name('browse.data');
    Route::post('browse/blacklist/{file}', [BrowseController::class, 'blacklist'])->name('browse.blacklist');
    Route::post('browse/undo-blacklist', [BrowseController::class, 'undoLastBlacklist'])->name('browse.undo-blacklist');
    Route::post('browse/download/{file}', [BrowseController::class, 'download'])->name('browse.download');

    Route::get('audio', function () {
        $search = [];

        if ($query = request()->input('query')) {
            // Filter search results to only include audio files that exist
            $search = \App\Models\File::search($query)
                ->query(function ($builder) {
                    $builder->where('mime_type', 'like', 'audio/%')
                            ->where('not_found', false);
                })
                ->get();

            // Load metadata, covers, artists, and albums relationships for search results
            if ($search->isNotEmpty()) {
                $search->load(['metadata', 'covers', 'artists', 'albums']);
            }
        }

        return Inertia::render('Audio', [
            'files' => fn () => \App\Models\File::audio()
                ->where('not_found', false)
                ->select(['id'])
                ->get(),
            'search' => $search,
        ]);
    })->name('audio');

    // Audio subitems routes (must come before audio/{file} route)
    Route::get('audio/favorites', [AudioController::class, 'favorites'])->name('audio.favorites');
    Route::get('audio/liked', [AudioController::class, 'liked'])->name('audio.liked');
    Route::get('audio/disliked', [AudioController::class, 'disliked'])->name('audio.disliked');
    Route::get('audio/unrated', [AudioController::class, 'unrated'])->name('audio.unrated');
    Route::get('audio/funny', [AudioController::class, 'funny'])->name('audio.funny');
    Route::get('audio/podcasts', [AudioController::class, 'podcasts'])->name('audio.podcasts');

    // Artists and Albums routes (moved to parent level)
    Route::get('artists', [AudioController::class, 'artists'])->name('artists.index');
    Route::get('albums', [AudioController::class, 'albums'])->name('albums.index');

    // Playlists routes (moved to parent level)
    Route::get('playlists', [AudioController::class, 'playlists'])->name('playlists.index');
    Route::post('playlists', [AudioController::class, 'storePlaylist'])->name('playlists.store');
    Route::get('playlists/{playlist}', [AudioController::class, 'showPlaylist'])->name('playlists.show');
    Route::post('playlists/{playlist}/files', [AudioController::class, 'addFileToPlaylist'])->name('playlists.files.store');

    Route::get('audio/{file}', [AudioController::class, 'show'])->name('audio.show');

    // Audio details route for AJAX loading
    Route::get('audio/{file}/details', [AudioController::class, 'getDetails'])->name('audio.details');

    // Batch audio details route for optimized loading
    Route::post('audio/batch-details', [AudioController::class, 'getBatchDetails'])->name('audio.batch-details');

    // Video routes
    Route::get('video', [VideoController::class, 'index'])->name('video.index');
    Route::get('video/movies', [VideoController::class, 'movies'])->name('video.movies');
    Route::get('video/series', [VideoController::class, 'series'])->name('video.series');
    Route::get('video/various', [VideoController::class, 'various'])->name('video.various');
    Route::get('video/{file}', [VideoController::class, 'show'])->name('video.show');

    // Images routes
    Route::get('images', [ImageController::class, 'index'])->name('images.index');
    Route::get('images/books', [ImageController::class, 'books'])->name('images.books');
    Route::get('images/sets', [ImageController::class, 'sets'])->name('images.sets');
    Route::get('images/various', [ImageController::class, 'various'])->name('images.various');
    Route::delete('images/{file}', [ImageController::class, 'deleteOrBlacklist'])->name('images.delete');
    Route::get('images/{file}', [ImageController::class, 'show'])->name('images.show');

    // Get playlist membership for a file
    Route::get('files/{file}/playlists', [AudioController::class, 'getFilePlaylistMembership'])->name('files.playlists');

    // Files routes (using AudioController for consistency)
    Route::post('covers/{coverId}', [AudioController::class, 'updateCover'])->name('covers.update');
    Route::post('covers/create/{fileId}', [AudioController::class, 'createCover'])->name('covers.create');

    // Audio streaming route
    Route::get('audio/stream/{id}', [AudioController::class, 'stream'])->name('audio.stream');

    // Audio interaction routes
    Route::post('audio/{file}/love', [AudioController::class, 'toggleLove'])->name('audio.love');
    Route::post('audio/{file}/like', [AudioController::class, 'toggleLike'])->name('audio.like');
    Route::post('audio/{file}/dislike', [AudioController::class, 'toggleDislike'])->name('audio.dislike');
    Route::post('audio/{file}/laughed-at', [AudioController::class, 'toggleLaughedAt'])->name('audio.laughed-at');

    // Generic file interaction routes (for all file types)
    Route::post('files/{file}/love', [AudioController::class, 'toggleLove'])->name('files.love');
    Route::post('files/{file}/like', [AudioController::class, 'toggleLike'])->name('files.like');
    Route::post('files/{file}/dislike', [AudioController::class, 'toggleDislike'])->name('files.dislike');
    Route::post('files/{file}/laughed-at', [AudioController::class, 'toggleLaughedAt'])->name('files.laughed-at');

    // Users routes
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

    // Files routes
    Route::get('files', [FileController::class, 'index'])->name('files.index');
    Route::post('files/{file}/seen', [FileController::class, 'markAsSeen'])->name('files.seen');
    Route::delete('files/{file}', [FileController::class, 'destroy'])->name('files.destroy');
    
    // Test route for Civitai API
    Route::get('test/civitai', [CivitaiTestController::class, 'testQuery'])->name('test.civitai');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
