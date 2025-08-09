<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\ModerationRuleController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance');

    // Moderation rules management
    Route::get('settings/moderation', [ModerationRuleController::class, 'index'])->name('moderation.index');
    Route::post('settings/moderation', [ModerationRuleController::class, 'store'])->name('moderation.store');
    Route::put('settings/moderation/{rule}', [ModerationRuleController::class, 'update'])->name('moderation.update');
    Route::delete('settings/moderation/{rule}', [ModerationRuleController::class, 'destroy'])->name('moderation.destroy');
});
