<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
})->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    // API routes (must come before SPA catch-all)
    Route::post('/profile/password', [\App\Http\Controllers\ProfileController::class, 'updatePassword'])->name('profile.password.update');
    Route::delete('/profile/account', [\App\Http\Controllers\ProfileController::class, 'deleteAccount'])->name('profile.account.delete');
    Route::get('/api/users', [\App\Http\Controllers\UsersController::class, 'index'])->name('api.users.index');
    Route::delete('/api/users/{user}', [\App\Http\Controllers\UsersController::class, 'destroy'])->name('api.users.destroy');
    Route::get('/api/files', [\App\Http\Controllers\FilesController::class, 'index'])->name('api.files.index');
    Route::get('/api/files/{file}', [\App\Http\Controllers\FilesController::class, 'show'])->name('api.files.show');
    Route::get('/api/files/{file}/serve', [\App\Http\Controllers\FilesController::class, 'serve'])->name('api.files.serve');
    Route::delete('/api/files/{file}', [\App\Http\Controllers\FilesController::class, 'destroy'])->name('api.files.destroy');
    Route::get('/api/files/{file}/reaction', [\App\Http\Controllers\FileReactionController::class, 'show'])->name('api.files.reaction.show');
    Route::post('/api/files/reactions/batch', [\App\Http\Controllers\FileReactionController::class, 'batchShow'])->name('api.files.reactions.batch');
    Route::post('/api/files/reactions/batch/store', [\App\Http\Controllers\FileReactionController::class, 'batchStore'])->name('api.files.reactions.batch.store');
    Route::post('/api/files/{file}/reaction', [\App\Http\Controllers\FileReactionController::class, 'store'])->name('api.files.reaction.store');
    Route::post('/api/files/{file}/preview', [\App\Http\Controllers\FilesController::class, 'incrementPreview'])->name('api.files.preview');
    Route::post('/api/files/preview/batch', [\App\Http\Controllers\FilesController::class, 'batchIncrementPreview'])->name('api.files.preview.batch');
    Route::post('/api/files/auto-dislike/batch', [\App\Http\Controllers\FilesController::class, 'batchPerformAutoDislike'])->name('api.files.auto-dislike.batch');
    Route::post('/api/files/{file}/seen', [\App\Http\Controllers\FilesController::class, 'incrementSeen'])->name('api.files.seen');
    Route::get('/api/browse', [\App\Http\Controllers\BrowseController::class, 'index'])->name('api.browse.index');
    Route::get('/api/browse/services', [\App\Http\Controllers\BrowseController::class, 'services'])->name('api.browse.services');
    Route::post('/api/browse/items', [\App\Http\Controllers\BrowseController::class, 'items'])->name('api.browse.items');
    Route::get('/api/tabs', [\App\Http\Controllers\TabController::class, 'index'])->name('api.tabs.index');
    Route::get('/api/tabs/{tab}/items', [\App\Http\Controllers\TabController::class, 'items'])->name('api.tabs.items');
    Route::post('/api/tabs', [\App\Http\Controllers\TabController::class, 'store'])->name('api.tabs.store');
    Route::put('/api/tabs/{tab}', [\App\Http\Controllers\TabController::class, 'update'])->name('api.tabs.update');
    Route::delete('/api/tabs/{tab}', [\App\Http\Controllers\TabController::class, 'destroy'])->name('api.tabs.destroy');
    Route::patch('/api/tabs/{tab}/position', [\App\Http\Controllers\TabController::class, 'updatePosition'])->name('api.tabs.position');
    Route::patch('/api/tabs/{tab}/active', [\App\Http\Controllers\TabController::class, 'setActive'])->name('api.tabs.active');
    Route::delete('/api/tabs', [\App\Http\Controllers\TabController::class, 'deleteAll'])->name('api.tabs.delete-all');
    Route::delete('/api/files', [\App\Http\Controllers\FilesController::class, 'deleteAll'])->name('api.files.delete-all');

    // Moderation Rules
    Route::get('/api/moderation-rules', [\App\Http\Controllers\ModerationRuleController::class, 'index'])->name('api.moderation-rules.index');
    Route::post('/api/moderation-rules', [\App\Http\Controllers\ModerationRuleController::class, 'store'])->name('api.moderation-rules.store');
    Route::get('/api/moderation-rules/{moderationRule}', [\App\Http\Controllers\ModerationRuleController::class, 'show'])->name('api.moderation-rules.show');
    Route::put('/api/moderation-rules/{moderationRule}', [\App\Http\Controllers\ModerationRuleController::class, 'update'])->name('api.moderation-rules.update');
    Route::delete('/api/moderation-rules/{moderationRule}', [\App\Http\Controllers\ModerationRuleController::class, 'destroy'])->name('api.moderation-rules.destroy');
    Route::post('/api/moderation-rules/test', [\App\Http\Controllers\ModerationRuleController::class, 'testRule'])->name('api.moderation-rules.test');

    // Container Blacklists
    Route::get('/api/container-blacklists', [\App\Http\Controllers\ContainerBlacklistController::class, 'index'])->name('api.container-blacklists.index');
    Route::post('/api/container-blacklists', [\App\Http\Controllers\ContainerBlacklistController::class, 'store'])->name('api.container-blacklists.store');
    Route::get('/api/container-blacklists/{container}/check', [\App\Http\Controllers\ContainerBlacklistController::class, 'check'])->name('api.container-blacklists.check');
    Route::delete('/api/container-blacklists/{container}', [\App\Http\Controllers\ContainerBlacklistController::class, 'destroy'])->name('api.container-blacklists.destroy');

    // SPA catch-all - serves the dashboard view for all GET requests
    // Vue Router handles client-side routing, but Laravel needs to serve the view
    // for direct navigation (e.g., refreshing the page or typing the URL)
    // API routes above (POST/DELETE) won't match this GET route
    Route::get('/{any}', function () {
        return view('dashboard');
    })->where('any', '.*')->name('spa');
});
