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

// Public extension API endpoints. Kept in web.php with SPA-style /api prefix; no api middleware throttling is applied.
Route::get('/api/extension/ping', [\App\Http\Controllers\ExtensionApiController::class, 'ping'])
    ->name('api.extension.ping');
Route::get('/api/extension/settings', [\App\Http\Controllers\ExtensionSettingsController::class, 'show'])
    ->name('api.extension.settings');
Route::post('/api/extension/settings', [\App\Http\Controllers\ExtensionSettingsController::class, 'store'])
    ->name('api.extension.settings.store');
Route::post('/api/extension/matches', [\App\Http\Controllers\ExtensionApiController::class, 'matches'])
    ->name('api.extension.matches');
Route::post('/api/extension/badges/checks', [\App\Http\Controllers\ExtensionApiController::class, 'badgeChecks'])
    ->name('api.extension.badges.checks');
Route::post('/api/extension/referrer-checks', [\App\Http\Controllers\ExtensionApiController::class, 'referrerChecks'])
    ->name('api.extension.referrer-checks');
Route::post('/api/extension/reactions', [\App\Http\Controllers\ExtensionApiController::class, 'react'])
    ->name('api.extension.reactions');
Route::post('/api/extension/reactions/batch', [\App\Http\Controllers\ExtensionApiController::class, 'reactBatch'])
    ->name('api.extension.reactions.batch');
Route::delete('/api/extension/files/{file}', [\App\Http\Controllers\ExtensionFileController::class, 'destroy'])
    ->name('api.extension.files.destroy');
Route::post('/api/extension/download-status', [\App\Http\Controllers\ExtensionApiController::class, 'downloadStatus'])
    ->name('api.extension.download-status');
Route::post('/api/extension/browse-tabs/civitai-model', [\App\Http\Controllers\ExtensionApiController::class, 'openCivitAiModelBrowseTab'])
    ->name('api.extension.browse-tabs.civitai-model');
Route::post('/api/extension/browse-tabs/civitai-user', [\App\Http\Controllers\ExtensionApiController::class, 'openCivitAiUserBrowseTab'])
    ->name('api.extension.browse-tabs.civitai-user');
Route::post('/api/extension/browse-tabs/deviantart-user', [\App\Http\Controllers\ExtensionApiController::class, 'openDeviantArtUserBrowseTab'])
    ->name('api.extension.browse-tabs.deviantart-user');
Route::post('/api/extension/broadcasting/auth', [\App\Http\Controllers\ExtensionApiController::class, 'broadcastAuth'])
    ->name('api.extension.broadcast-auth');

Route::post('/api/media-processor/tasks/{mediaProcessorTask}/events', \App\Http\Controllers\MediaProcessorTaskEventController::class)
    ->name('api.media-processor.tasks.events');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/auth/spotify/redirect', [\App\Http\Controllers\SettingsServicesController::class, 'spotifyRedirect'])
        ->name('auth.spotify.redirect');
    Route::get('/auth/spotify/callback', [\App\Http\Controllers\SettingsServicesController::class, 'spotifyCallback'])
        ->name('auth.spotify.callback');
    Route::get('/auth/deviantart/redirect', [\App\Http\Controllers\SettingsServicesController::class, 'deviantArtRedirect'])
        ->name('auth.deviantart.redirect');
    Route::get('/auth/deviantart/callback', [\App\Http\Controllers\SettingsServicesController::class, 'deviantArtCallback'])
        ->name('auth.deviantart.callback');

    // API routes (must come before SPA catch-all)
    Route::get('/api/settings/services', [\App\Http\Controllers\SettingsServicesController::class, 'index'])
        ->name('api.settings.services.index');
    Route::post('/api/settings/extension', [\App\Http\Controllers\SettingsServicesController::class, 'extensionApiKeyStore'])
        ->name('api.settings.extension.store');
    Route::post('/api/settings/extension/generate', [\App\Http\Controllers\SettingsServicesController::class, 'extensionApiKeyGenerate'])
        ->name('api.settings.extension.generate');
    Route::get('/api/settings/infrastructure-health', [\App\Http\Controllers\SettingsServicesController::class, 'infrastructureHealth'])
        ->name('api.settings.infrastructure-health');
    Route::post('/api/settings/services/spotify/refresh', [\App\Http\Controllers\SettingsServicesController::class, 'spotifyRefresh'])
        ->name('api.settings.services.spotify.refresh');
    Route::delete('/api/settings/services/spotify', [\App\Http\Controllers\SettingsServicesController::class, 'spotifyDisconnect'])
        ->name('api.settings.services.spotify.disconnect');
    Route::get('/api/spotify/playback-token', \App\Http\Controllers\SpotifyPlaybackTokenController::class)
        ->name('api.spotify.playback-token');
    Route::post('/api/settings/services/deviantart/refresh', [\App\Http\Controllers\SettingsServicesController::class, 'deviantArtRefresh'])
        ->name('api.settings.services.deviantart.refresh');
    Route::delete('/api/settings/services/deviantart', [\App\Http\Controllers\SettingsServicesController::class, 'deviantArtDisconnect'])
        ->name('api.settings.services.deviantart.disconnect');
    Route::get('/api/settings/library-scans', [\App\Http\Controllers\LibraryScanController::class, 'index'])
        ->name('api.settings.library-scans.index');
    Route::post('/api/settings/library-scans', [\App\Http\Controllers\LibraryScanController::class, 'store'])
        ->name('api.settings.library-scans.store');
    Route::post('/api/settings/library-scans/reparse-imported', [\App\Http\Controllers\LibraryScanController::class, 'reparseImported'])
        ->name('api.settings.library-scans.reparse-imported');
    Route::post('/api/settings/library-scans/reparse-imported/audio', [\App\Http\Controllers\LibraryScanController::class, 'reparseImportedAudio'])
        ->name('api.settings.library-scans.reparse-imported.audio');
    Route::get('/api/settings/moderation-feed-removal-runs', [\App\Http\Controllers\ModerationFeedRemovalRunController::class, 'index'])
        ->name('api.settings.moderation-feed-removal-runs.index');
    Route::post('/api/settings/moderation-feed-removal-runs/preview', [\App\Http\Controllers\ModerationFeedRemovalRunController::class, 'preview'])
        ->name('api.settings.moderation-feed-removal-runs.preview');
    Route::post('/api/settings/moderation-feed-removal-runs/{moderationFeedRemovalRun}/apply', [\App\Http\Controllers\ModerationFeedRemovalRunController::class, 'apply'])
        ->name('api.settings.moderation-feed-removal-runs.apply');
    Route::get('/api/settings/library-scans/{libraryScanRun}', [\App\Http\Controllers\LibraryScanController::class, 'show'])
        ->name('api.settings.library-scans.show');
    Route::post('/api/settings/library-scans/{libraryScanRun}/pause', [\App\Http\Controllers\LibraryScanController::class, 'pause'])
        ->name('api.settings.library-scans.pause');
    Route::post('/api/settings/library-scans/{libraryScanRun}/resume', [\App\Http\Controllers\LibraryScanController::class, 'resume'])
        ->name('api.settings.library-scans.resume');
    Route::post('/api/settings/library-scans/{libraryScanRun}/cancel', [\App\Http\Controllers\LibraryScanController::class, 'cancel'])
        ->name('api.settings.library-scans.cancel');
    Route::post('/api/settings/library-scans/{libraryScanRun}/restart', [\App\Http\Controllers\LibraryScanController::class, 'restart'])
        ->name('api.settings.library-scans.restart');
    Route::get('/settings/browser-extension/download', [\App\Http\Controllers\BrowserExtensionDownloadController::class, 'download'])
        ->name('settings.browser-extension.download');

    Route::post('/profile/password', [\App\Http\Controllers\ProfileController::class, 'updatePassword'])->name('profile.password.update');
    Route::delete('/profile/account', [\App\Http\Controllers\ProfileController::class, 'deleteAccount'])->name('profile.account.delete');
    Route::get('/api/users', [\App\Http\Controllers\UsersController::class, 'index'])->name('api.users.index');
    Route::delete('/api/users/{user}', [\App\Http\Controllers\UsersController::class, 'destroy'])->name('api.users.destroy');
    Route::get('/api/dashboard/metrics', DashboardMetricsController::class)->name('api.dashboard.metrics');
    Route::get('/api/files', [\App\Http\Controllers\FilesController::class, 'index'])->name('api.files.index');
    Route::get('/api/audio/playlists', [\App\Http\Controllers\AudioPlaylistController::class, 'index'])->name('api.audio.playlists.index');
    Route::post('/api/audio/playlists/membership', [\App\Http\Controllers\AudioPlaylistController::class, 'membership'])->name('api.audio.playlists.membership');
    Route::patch('/api/audio/playlists/{playlist}/cover', [\App\Http\Controllers\AudioPlaylistController::class, 'updateCover'])->name('api.audio.playlists.cover.update');
    Route::get('/api/audio/ids', [\App\Http\Controllers\AudioController::class, 'ids'])->name('api.audio.ids');
    Route::post('/api/audio/details', [\App\Http\Controllers\AudioController::class, 'details'])->name('api.audio.details');
    Route::post('/api/audio/metadata-runs', [\App\Http\Controllers\AudioMetadataController::class, 'store'])->name('api.audio.metadata-runs.store');
    Route::get('/api/audio/metadata-runs/active', [\App\Http\Controllers\AudioMetadataController::class, 'activeBatch'])->name('api.audio.metadata-runs.active');
    Route::get('/api/audio/metadata-runs/{audioMetadataRun}', [\App\Http\Controllers\AudioMetadataController::class, 'showRun'])->name('api.audio.metadata-runs.show');
    Route::post('/api/audio/metadata-runs/{audioMetadataRun}/pause', [\App\Http\Controllers\AudioMetadataController::class, 'pause'])->name('api.audio.metadata-runs.pause');
    Route::post('/api/audio/metadata-runs/{audioMetadataRun}/resume', [\App\Http\Controllers\AudioMetadataController::class, 'resume'])->name('api.audio.metadata-runs.resume');
    Route::post('/api/audio/metadata-runs/{audioMetadataRun}/cancel', [\App\Http\Controllers\AudioMetadataController::class, 'cancel'])->name('api.audio.metadata-runs.cancel');
    Route::patch('/api/audio/metadata-proposals/{audioMetadataProposal}', [\App\Http\Controllers\AudioMetadataController::class, 'review'])->name('api.audio.metadata-proposals.review');
    Route::get('/api/audio/{file}/metadata-proposals/latest', [\App\Http\Controllers\AudioMetadataController::class, 'latestForFile'])->name('api.audio.files.metadata-proposals.latest');
    Route::post('/api/audio/{file}/metadata/restore-from-file', [\App\Http\Controllers\AudioMetadataController::class, 'restoreFromFile'])->name('api.audio.files.metadata.restore-from-file');
    Route::post('/api/audio/{file}/metadata-runs', [\App\Http\Controllers\AudioMetadataController::class, 'storeForFile'])->name('api.audio.files.metadata-runs.store');
    Route::post('/api/audio/playback-events', [\App\Http\Controllers\AudioPlaybackEventController::class, 'store'])->name('api.audio.playback-events.store');
    Route::get('/api/audio/album-covers/{albumCover}', [\App\Http\Controllers\AlbumCoverController::class, 'show'])->name('api.audio.album-covers.show');
    Route::get('/api/files/{file}', [\App\Http\Controllers\FilesController::class, 'show'])->name('api.files.show');
    Route::post('/api/files/{file}/source-metadata/{target}', \App\Http\Controllers\FileSourceMetadataController::class)
        ->whereIn('target', \App\Enums\SourceMetadataRestoreTarget::values())
        ->name('api.files.source-metadata.refresh');
    Route::post('/api/files/{file}/civitai-metadata', \App\Http\Controllers\CivitAiFileMetadataController::class)->name('api.files.civitai-metadata.restore');
    Route::post('/api/files/{file}/refresh-source-media', [\App\Http\Controllers\FilesController::class, 'refreshSourceMedia'])->name('api.files.refresh-source-media');
    Route::post('/api/files/{file}/source-watch-refresh', [\App\Http\Controllers\FilesController::class, 'watchSourceAndRefreshMedia'])->name('api.files.source-watch-refresh');
    Route::post('/api/files/{file}/source-unwatch', [\App\Http\Controllers\FilesController::class, 'unwatchSourceAccount'])->name('api.files.source-unwatch');
    Route::post('/api/files/{file}/redownload', [\App\Http\Controllers\FilesController::class, 'redownload'])->name('api.files.redownload');
    Route::delete('/api/files/{file}/corrupted', [\App\Http\Controllers\FilesController::class, 'markCorrupted'])->name('api.files.corrupted');
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
    Route::post('/api/files/{file}/preview-failure', [\App\Http\Controllers\FilesController::class, 'reportPreviewFailure'])->name('api.files.preview.report-failure');
    Route::post('/api/files/preview/batch', [\App\Http\Controllers\FilesController::class, 'batchIncrementPreview'])->name('api.files.preview.batch');
    Route::post('/api/files/preview/reset/batch', [\App\Http\Controllers\FilesController::class, 'batchResetPreview'])->name('api.files.preview.reset-batch');
    Route::post('/api/files/blacklist/batch', [\App\Http\Controllers\FilesController::class, 'batchBlacklist'])->name('api.files.blacklist.batch');
    Route::delete('/api/files/{file}/blacklist', [\App\Http\Controllers\FilesController::class, 'clearBlacklist'])->name('api.files.blacklist.destroy');
    Route::post('/api/files/{file}/seen', [\App\Http\Controllers\FilesController::class, 'incrementSeen'])->name('api.files.seen');
    Route::get('/api/browse', [\App\Http\Controllers\BrowseController::class, 'index'])->name('api.browse.index');
    Route::get('/api/browse/services', [\App\Http\Controllers\BrowseController::class, 'services'])->name('api.browse.services');
    Route::get('/api/browse/sources', [\App\Http\Controllers\BrowseController::class, 'sources'])->name('api.browse.sources');
    Route::get('/api/tabs', [\App\Http\Controllers\TabController::class, 'index'])->name('api.tabs.index');
    Route::get('/api/tabs/{tab}', [\App\Http\Controllers\TabController::class, 'show'])->name('api.tabs.show');
    Route::post('/api/tabs', [\App\Http\Controllers\TabController::class, 'store'])->name('api.tabs.store');
    Route::put('/api/tabs/{tab}', [\App\Http\Controllers\TabController::class, 'update'])->name('api.tabs.update');
    Route::delete('/api/tabs/{tab}/files', [\App\Http\Controllers\TabController::class, 'detachFiles'])->name('api.tabs.files.detach');
    Route::delete('/api/tabs/{tab}', [\App\Http\Controllers\TabController::class, 'destroy'])->name('api.tabs.destroy');
    Route::post('/api/tabs/reorder', [\App\Http\Controllers\TabController::class, 'reorder'])->name('api.tabs.reorder');
    Route::post('/api/tabs/bulk-delete', [\App\Http\Controllers\TabController::class, 'destroyBatch'])->name('api.tabs.destroy-batch');
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
    Route::post('/api/download-transfers/bulk-delete-completed', [\App\Http\Controllers\DownloadTransferActionsController::class, 'destroyCompleted'])->name('api.download-transfers.destroy-completed');
    Route::delete('/api/download-transfers/{downloadTransfer}/disk', [\App\Http\Controllers\DownloadTransferActionsController::class, 'destroyWithDisk'])->name('api.download-transfers.destroy-disk');
    Route::delete('/api/download-transfers/{downloadTransfer}', [\App\Http\Controllers\DownloadTransferActionsController::class, 'destroy'])->name('api.download-transfers.destroy');
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
