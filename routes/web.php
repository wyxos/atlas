<?php

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
            $search = \App\Models\File::search($query)->get();
        }

        return Inertia::render('Audio', [
            'files' => fn() => \App\Models\File::audio()
                ->select(['id', 'mime_type'])
                ->with('metadata')->get(),
            'search' => $search,
        ]);
    })->name('audio');

    // Audio streaming route
    Route::get('audio/stream/{id}', [\App\Http\Controllers\AudioController::class, 'stream'])->name('audio.stream');
});






require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
