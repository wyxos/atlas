<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\PluginsController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance');

    // Plugins (admin-only enforced in controller)
    Route::get('settings/plugins', [PluginsController::class, 'index'])->name('plugins.edit');
    Route::post('settings/plugins/install', [PluginsController::class, 'install'])->name('plugins.install');
    Route::post('settings/plugins/uninstall', [PluginsController::class, 'uninstall'])->name('plugins.uninstall');

    // Storage settings (atlas path)
    Route::get('settings/storage', [App\Http\Controllers\Settings\StoragePathController::class, 'edit'])->name('storage.edit');
    Route::get('settings/library', [App\Http\Controllers\Settings\StoragePathController::class, 'edit'])->name('library.edit');
    Route::put('settings/storage', [App\Http\Controllers\Settings\StoragePathController::class, 'update'])->name('storage.update');

    // Storage scan actions
    Route::post('settings/storage/scan-start', [App\Http\Controllers\Settings\StorageScanController::class, 'start'])->name('storage.scan.start');
    Route::post('settings/storage/scan-cancel', [App\Http\Controllers\Settings\StorageScanController::class, 'cancel'])->name('storage.scan.cancel');

    // Spotify settings and scan
    Route::get('settings/spotify', [App\Http\Controllers\Settings\SpotifyController::class, 'edit'])->name('spotify.edit');
    Route::post('settings/spotify/scan-start', [App\Http\Controllers\Settings\SpotifyController::class, 'start'])->name('spotify.scan.start');
    Route::post('settings/spotify/scan-cancel', [App\Http\Controllers\Settings\SpotifyController::class, 'cancel'])->name('spotify.scan.cancel');
});
