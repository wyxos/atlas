<?php

use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('extension delete-download resets downloaded file assets', function () {
    Storage::fake('atlas-app');

    config()->set('downloads.extension_token', 'test-token');

    $file = File::factory()->create([
        'url' => 'https://example.com/media/one.jpg',
        'referrer_url' => 'https://example.com/media/one.jpg',
        'downloaded' => true,
        'path' => 'downloads/original.jpg',
        'preview_path' => 'downloads/preview.jpg',
    ]);

    Storage::disk('atlas-app')->put($file->path, 'original');
    Storage::disk('atlas-app')->put($file->preview_path, 'preview');

    $response = $this
        ->withHeader('X-Atlas-Extension-Token', 'test-token')
        ->postJson('/api/extension/files/delete-download', [
            'url' => 'https://example.com/media/one.jpg',
        ]);

    $response->assertOk();
    $response->assertJsonPath('file.downloaded', false);

    $file->refresh();
    expect($file->downloaded)->toBeFalse()
        ->and($file->path)->toBeNull()
        ->and($file->preview_path)->toBeNull();

    Storage::disk('atlas-app')->assertMissing('downloads/original.jpg');
    Storage::disk('atlas-app')->assertMissing('downloads/preview.jpg');
});

test('extension delete-download returns 404 when file is unknown', function () {
    config()->set('downloads.extension_token', 'test-token');

    $response = $this
        ->withHeader('X-Atlas-Extension-Token', 'test-token')
        ->postJson('/api/extension/files/delete-download', [
            'url' => 'https://example.com/media/missing.jpg',
        ]);

    $response->assertNotFound();
});
