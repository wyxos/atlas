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
            // Filter search results to only include audio files
            $search = \App\Models\File::search($query)
                ->query(function ($builder) {
                    $builder->where('mime_type', 'like', 'audio/%');
                })
                ->get();

            // Load metadata, covers, artists, and albums relationships for search results
            if ($search->isNotEmpty()) {
                $search->load(['metadata', 'covers', 'artists', 'albums']);
            }
        }

        return Inertia::render('Audio', [
            'files' => fn () => \App\Models\File::audio()
                ->select(['id'])
                ->get(),
            'search' => $search,
        ]);
    })->name('audio');

    Route::get('audio/{file}', [AudioController::class, 'show'])->name('audio.show');

    // Audio details route for AJAX loading
    Route::get('audio/{file}/details', [AudioController::class, 'getDetails'])->name('audio.details');

    // Audio streaming route
    Route::get('audio/stream/{id}', [AudioController::class, 'stream'])->name('audio.stream');

    // Users routes
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
