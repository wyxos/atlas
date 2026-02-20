<?php

use App\Events\DownloadTransferProgressUpdated;
use App\Events\DownloadTransferQueued;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardMetricsController;
use App\Models\DownloadTransfer;
use App\Services\Downloads\DownloadTransferPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()
        ->view('home')
        // Avoid serving stale SPA HTML across deploys (hashed Vite assets).
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->header('Pragma', 'no-cache');
})->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

// Lightweight endpoint to refresh the XSRF cookie for SPA requests.
Route::get('/api/csrf', function () {
    return response()->noContent();
})->name('api.csrf');

Route::post('/api/extension/files', [\App\Http\Controllers\ExternalFilesController::class, 'store'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('api.extension.files.store');
Route::options('/api/extension/files', function () {
    return response()->noContent()
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, X-Atlas-Extension-Token, Authorization');
})->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

Route::post('/api/extension/files/react', [\App\Http\Controllers\ExternalFilesController::class, 'react'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('api.extension.files.react');
Route::options('/api/extension/files/react', function () {
    return response()->noContent()
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, X-Atlas-Extension-Token, Authorization');
})->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

Route::post('/api/extension/files/check', [\App\Http\Controllers\ExternalFilesController::class, 'check'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('api.extension.files.check');
Route::options('/api/extension/files/check', function () {
    return response()->noContent()
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, X-Atlas-Extension-Token, Authorization');
})->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

Route::post('/api/extension/files/delete-download', [\App\Http\Controllers\ExternalFilesController::class, 'deleteDownload'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('api.extension.files.delete-download');
Route::options('/api/extension/files/delete-download', function () {
    return response()->noContent()
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, X-Atlas-Extension-Token, Authorization');
})->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

Route::get('/api/extension/realtime', [\App\Http\Controllers\ExtensionRealtimeController::class, 'config'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('api.extension.realtime.config');
Route::options('/api/extension/realtime', function () {
    return response()->noContent()
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, X-Atlas-Extension-Token, Authorization');
})->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

Route::post('/api/extension/broadcasting/auth', [\App\Http\Controllers\ExtensionRealtimeController::class, 'auth'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('api.extension.realtime.auth');
Route::options('/api/extension/broadcasting/auth', function () {
    return response()->noContent()
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, X-Atlas-Extension-Token, Authorization');
})->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    // API routes (must come before SPA catch-all)
    Route::post('/profile/password', [\App\Http\Controllers\ProfileController::class, 'updatePassword'])->name('profile.password.update');
    Route::delete('/profile/account', [\App\Http\Controllers\ProfileController::class, 'deleteAccount'])->name('profile.account.delete');
    Route::get('/api/users', [\App\Http\Controllers\UsersController::class, 'index'])->name('api.users.index');
    Route::delete('/api/users/{user}', [\App\Http\Controllers\UsersController::class, 'destroy'])->name('api.users.destroy');
    Route::get('/api/dashboard/metrics', DashboardMetricsController::class)->name('api.dashboard.metrics');
    Route::get('/api/files', [\App\Http\Controllers\FilesController::class, 'index'])->name('api.files.index');
    Route::get('/api/files/{file}', [\App\Http\Controllers\FilesController::class, 'show'])->name('api.files.show');
    Route::get('/api/files/{file}/serve', [\App\Http\Controllers\FilesController::class, 'serve'])->name('api.files.serve');
    Route::get('/api/files/{file}/downloaded', [\App\Http\Controllers\FilesController::class, 'serveDownloaded'])->name('api.files.downloaded');
    Route::get('/api/files/{file}/preview', [\App\Http\Controllers\FilesController::class, 'servePreview'])->name('api.files.preview');
    Route::get('/api/files/{file}/icon', [\App\Http\Controllers\FilesController::class, 'serveIcon'])->name('api.files.icon');
    Route::get('/api/files/{file}/poster', [\App\Http\Controllers\FilesController::class, 'serveVideoPoster'])->name('api.files.poster');
    Route::delete('/api/files/{file}', [\App\Http\Controllers\FilesController::class, 'destroy'])->name('api.files.destroy');
    Route::get('/api/files/{file}/reaction', [\App\Http\Controllers\FileReactionController::class, 'show'])->name('api.files.reaction.show');
    Route::post('/api/files/reactions/batch', [\App\Http\Controllers\FileReactionController::class, 'batchShow'])->name('api.files.reactions.batch');
    Route::post('/api/files/reactions/batch/store', [\App\Http\Controllers\FileReactionController::class, 'batchStore'])->name('api.files.reactions.batch.store');
    Route::post('/api/files/{file}/reaction', [\App\Http\Controllers\FileReactionController::class, 'store'])->name('api.files.reaction.store');
    Route::post('/api/files/{file}/preview', [\App\Http\Controllers\FilesController::class, 'incrementPreview'])->name('api.files.preview.increment');
    Route::post('/api/files/preview/batch', [\App\Http\Controllers\FilesController::class, 'batchIncrementPreview'])->name('api.files.preview.batch');
    Route::post('/api/files/auto-dislike/batch', [\App\Http\Controllers\FilesController::class, 'batchPerformAutoDislike'])->name('api.files.auto-dislike.batch');
    Route::post('/api/files/{file}/seen', [\App\Http\Controllers\FilesController::class, 'incrementSeen'])->name('api.files.seen');
    Route::get('/api/browse', [\App\Http\Controllers\BrowseController::class, 'index'])->name('api.browse.index');
    Route::get('/api/browse/services', [\App\Http\Controllers\BrowseController::class, 'services'])->name('api.browse.services');
    Route::get('/api/browse/sources', [\App\Http\Controllers\BrowseController::class, 'sources'])->name('api.browse.sources');
    Route::get('/api/tabs', [\App\Http\Controllers\TabController::class, 'index'])->name('api.tabs.index');
    Route::get('/api/tabs/{tab}', [\App\Http\Controllers\TabController::class, 'show'])->name('api.tabs.show');
    Route::post('/api/tabs', [\App\Http\Controllers\TabController::class, 'store'])->name('api.tabs.store');
    Route::put('/api/tabs/{tab}', [\App\Http\Controllers\TabController::class, 'update'])->name('api.tabs.update');
    Route::delete('/api/tabs/{tab}', [\App\Http\Controllers\TabController::class, 'destroy'])->name('api.tabs.destroy');
    Route::patch('/api/tabs/{tab}/position', [\App\Http\Controllers\TabController::class, 'updatePosition'])->name('api.tabs.position');
    Route::patch('/api/tabs/{tab}/active', [\App\Http\Controllers\TabController::class, 'setActive'])->name('api.tabs.active');
    Route::delete('/api/tabs', [\App\Http\Controllers\TabController::class, 'deleteAll'])->name('api.tabs.delete-all');
    Route::delete('/api/files', [\App\Http\Controllers\FilesController::class, 'deleteAll'])->name('api.files.delete-all');
    Route::get('/api/download-transfers', [\App\Http\Controllers\DownloadTransfersController::class, 'index'])->name('api.download-transfers.index');
    Route::post('/api/download-transfers/details', [\App\Http\Controllers\DownloadTransfersController::class, 'details'])->name('api.download-transfers.details');
    Route::post('/api/download-transfers/{downloadTransfer}/pause', [\App\Http\Controllers\DownloadTransferActionsController::class, 'pause'])->name('api.download-transfers.pause');
    Route::post('/api/download-transfers/{downloadTransfer}/resume', [\App\Http\Controllers\DownloadTransferActionsController::class, 'resume'])->name('api.download-transfers.resume');
    Route::post('/api/download-transfers/{downloadTransfer}/cancel', [\App\Http\Controllers\DownloadTransferActionsController::class, 'cancel'])->name('api.download-transfers.cancel');
    Route::post('/api/download-transfers/{downloadTransfer}/restart', [\App\Http\Controllers\DownloadTransferActionsController::class, 'restart'])->name('api.download-transfers.restart');
    Route::post('/api/download-transfers/bulk-pause', [\App\Http\Controllers\DownloadTransferActionsController::class, 'pauseBatchTransfers'])->name('api.download-transfers.pause-batch');
    Route::post('/api/download-transfers/bulk-cancel', [\App\Http\Controllers\DownloadTransferActionsController::class, 'cancelBatchTransfers'])->name('api.download-transfers.cancel-batch');
    Route::post('/api/download-transfers/bulk-delete', [\App\Http\Controllers\DownloadTransferActionsController::class, 'destroyBatch'])->name('api.download-transfers.destroy-batch');
    Route::delete('/api/download-transfers/{downloadTransfer}/disk', [\App\Http\Controllers\DownloadTransferActionsController::class, 'destroyWithDisk'])->name('api.download-transfers.destroy-disk');
    Route::delete('/api/download-transfers/{downloadTransfer}', [\App\Http\Controllers\DownloadTransferActionsController::class, 'destroy'])->name('api.download-transfers.destroy');
    Route::get('/downloads/atlas-extension.zip', [\App\Http\Controllers\ExtensionDownloadController::class, 'download'])
        ->name('downloads.atlas-extension');

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

    Route::get('/reverb-test', function () {
        return view('reverb-test');
    })->name('reverb.test');

    Route::post('/reverb-test/trigger', function (Request $request) {
        $downloadTransferId = $request->integer('downloadTransferId', random_int(1000, 9999));
        $status = $request->string('status', 'processing')->toString();
        $percent = $request->integer('percent', random_int(1, 100));

        $transfer = DownloadTransfer::query()
            ->with(['file:id,filename,path,url,thumbnail_url,size'])
            ->find($downloadTransferId);

        if ($transfer) {
            event(new DownloadTransferQueued(DownloadTransferPayload::forQueued($transfer)));
            event(new DownloadTransferProgressUpdated(DownloadTransferPayload::forProgress($transfer, $percent)));
        } else {
            $now = now()->toISOString();
            event(new DownloadTransferQueued([
                'id' => $downloadTransferId,
                'status' => $status,
                'queued_at' => $now,
                'started_at' => null,
                'finished_at' => null,
                'failed_at' => null,
                'percent' => $percent,
                'path' => null,
                'original' => null,
                'preview' => null,
                'size' => null,
                'filename' => null,
            ]));
            event(new DownloadTransferProgressUpdated([
                'downloadTransferId' => $downloadTransferId,
                'status' => $status,
                'percent' => $percent,
                'started_at' => null,
                'finished_at' => null,
                'failed_at' => null,
            ]));
        }

        return response()->noContent();
    })->name('reverb.test.trigger');

    // SPA catch-all - serves the dashboard view for all GET requests
    // Vue Router handles client-side routing, but Laravel needs to serve the view
    // for direct navigation (e.g., refreshing the page or typing the URL)
    // API routes above (POST/DELETE) won't match this GET route
    Route::get('/{any}', function () {
        return response()
            ->view('dashboard')
            // Avoid serving stale SPA HTML across deploys (hashed Vite assets).
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    })->where('any', '.*')->name('spa');
});
