<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
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

test('extension delete-download removes current user reaction and keeps file row', function () {
    Storage::fake('atlas-app');

    config()->set('downloads.extension_token', 'test-token');
    $user = User::factory()->create();
    config()->set('downloads.extension_user_id', $user->id);

    $file = File::factory()->create([
        'url' => 'https://example.com/media/with-reaction.jpg',
        'referrer_url' => 'https://example.com/page/with-reaction',
        'downloaded' => true,
        'path' => 'downloads/with-reaction.jpg',
    ]);

    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    $this
        ->withHeader('X-Atlas-Extension-Token', 'test-token')
        ->postJson('/api/extension/files/delete-download', [
            'url' => 'https://example.com/media/with-reaction.jpg',
        ])
        ->assertOk()
        ->assertJsonPath('file.downloaded', false);

    expect(File::query()->whereKey($file->id)->exists())->toBeTrue()
        ->and(
            Reaction::query()
                ->where('file_id', $file->id)
                ->where('user_id', $user->id)
                ->exists()
        )->toBeFalse();
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
