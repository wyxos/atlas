<?php

use App\Http\Controllers\AudioController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::group(['middleware' => ['auth', 'verified']], function () {
    Route::get('dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    Route::get('audio', function(){
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
            'files' => fn() => \App\Models\File::audio()
                ->select(['id'])
                ->get(),
            'search' => $search,
        ]);
    })->name('audio');

    Route::get('audio/{file}', [AudioController::class, 'show'])->name('audio.show');

    // Audio streaming route
    Route::get('audio/stream/{id}', [AudioController::class, 'stream'])->name('audio.stream');
});


require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
