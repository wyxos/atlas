<?php

use App\Http\Controllers\AudioController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::group(['middleware' => ['auth', 'verified']], function () {
    Route::get('dashboard', [App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard');
    Route::get('dashboard/file-stats', [App\Http\Controllers\DashboardController::class, 'getFileStatsJson'])->name('dashboard.file-stats');

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
    Route::get('audio/artists', [AudioController::class, 'artists'])->name('audio.artists');
    Route::get('audio/albums', [AudioController::class, 'albums'])->name('audio.albums');
    Route::get('audio/playlists', [AudioController::class, 'playlists'])->name('audio.playlists');

    Route::get('audio/{file}', [AudioController::class, 'show'])->name('audio.show');

    // Audio details route for AJAX loading
    Route::get('audio/{file}/details', [AudioController::class, 'getDetails'])->name('audio.details');

    // Files routes (using AudioController for consistency)
    Route::get('files/{file}', [AudioController::class, 'show'])->name('files.show');
    Route::post('covers/{coverId}', [AudioController::class, 'updateCover'])->name('covers.update');

    // Audio streaming route
    Route::get('audio/stream/{id}', [AudioController::class, 'stream'])->name('audio.stream');

    // Audio interaction routes
    Route::post('audio/{file}/love', [AudioController::class, 'toggleLove'])->name('audio.love');
    Route::post('audio/{file}/like', [AudioController::class, 'toggleLike'])->name('audio.like');
    Route::post('audio/{file}/dislike', [AudioController::class, 'toggleDislike'])->name('audio.dislike');

    // Users routes
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
