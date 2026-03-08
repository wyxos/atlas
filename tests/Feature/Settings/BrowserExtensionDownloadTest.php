<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

function withIsolatedBrowserExtensionArchives(callable $callback): void
{
    $downloadsDirectory = storage_path('app/browser-extension-downloads');
    File::ensureDirectoryExists($downloadsDirectory);

    $backupDirectory = storage_path('framework/testing/browser-extension-download-'.bin2hex(random_bytes(8)));
    File::ensureDirectoryExists($backupDirectory);

    $existingArchives = glob($downloadsDirectory.DIRECTORY_SEPARATOR.'atlas-extension*.zip') ?: [];

    foreach ($existingArchives as $archivePath) {
        File::move($archivePath, $backupDirectory.DIRECTORY_SEPARATOR.basename($archivePath));
    }

    try {
        $callback($downloadsDirectory);
    } finally {
        $testArchives = glob($downloadsDirectory.DIRECTORY_SEPARATOR.'atlas-extension*.zip') ?: [];
        foreach ($testArchives as $archivePath) {
            File::delete($archivePath);
        }

        $backedUpArchives = glob($backupDirectory.DIRECTORY_SEPARATOR.'atlas-extension*.zip') ?: [];
        foreach ($backedUpArchives as $archivePath) {
            File::move($archivePath, $downloadsDirectory.DIRECTORY_SEPARATOR.basename($archivePath));
        }

        File::deleteDirectory($backupDirectory);
    }
}

test('browser extension download prefers the canonical archive', function () {
    $user = User::factory()->create();

    withIsolatedBrowserExtensionArchives(function (string $downloadsDirectory) use ($user): void {
        $canonicalArchivePath = $downloadsDirectory.DIRECTORY_SEPARATOR.'atlas-extension.zip';
        $versionedArchivePath = $downloadsDirectory.DIRECTORY_SEPARATOR.'atlas-extension-9.9.9.zip';

        file_put_contents($canonicalArchivePath, 'canonical archive');
        file_put_contents($versionedArchivePath, 'versioned archive');
        touch($versionedArchivePath, time() + 60);

        $response = $this->actingAs($user)->get('/settings/browser-extension/download');

        $response->assertDownload('atlas-extension.zip');
        expect($response->baseResponse->getFile()->getPathname())->toBe($canonicalArchivePath);
    });
});

test('browser extension download returns not found when no archive exists', function () {
    $user = User::factory()->create();

    withIsolatedBrowserExtensionArchives(function () use ($user): void {
        $response = $this->actingAs($user)->get('/settings/browser-extension/download');

        $response->assertNotFound();
    });
});
