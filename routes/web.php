<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::group(['auth', 'verified'], function () {
    Route::get('dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    Route::get('audio', function(){
        return Inertia::render('Audio', [
            'files' => \App\Models\File::audio()->select(['id'])->with('metadata')->get(),
        ]);
    })->name('audio');
});






require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
