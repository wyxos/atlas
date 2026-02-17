<?php

use App\Jobs\DownloadFile;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('extension react returns json and dispatches download for positive reactions', function () {
    Queue::fake();

    config()->set('downloads.extension_token', 'test-token');
    $user = User::factory()->create();
    config()->set('downloads.extension_user_id', $user->id);

    $response = $this
        ->withHeader('X-Atlas-Extension-Token', 'test-token')
        ->postJson('/api/extension/files/react', [
            'type' => 'love',
            'url' => 'https://example.com/media/one.jpg',
            'original_url' => 'https://example.com/media/one.jpg',
            'referrer_url' => 'https://example.com/page',
            'page_title' => str_repeat('A', 600),
            'alt' => str_repeat('B', 600),
            'source' => 'Extension',
        ]);

    $response->assertOk();
    $response->assertJsonPath('reaction.type', 'love');
    $response->assertJsonPath('file.referrer_url', 'https://example.com/media/one.jpg');

    Queue::assertPushed(DownloadFile::class);
});

test('extension react does not dispatch download for dislike', function () {
    Queue::fake();

    config()->set('downloads.extension_token', 'test-token');
    $user = User::factory()->create();
    config()->set('downloads.extension_user_id', $user->id);

    $response = $this
        ->withHeader('X-Atlas-Extension-Token', 'test-token')
        ->postJson('/api/extension/files/react', [
            'type' => 'dislike',
            'url' => 'https://example.com/media/two.jpg',
            'original_url' => 'https://example.com/media/two.jpg',
            'source' => 'Extension',
        ]);

    $response->assertOk();
    $response->assertJsonPath('reaction.type', 'dislike');
    Queue::assertNotPushed(DownloadFile::class);
});

test('extension react can force a re-download for already downloaded files', function () {
    Queue::fake();
    Storage::fake('atlas-app');

    config()->set('downloads.extension_token', 'test-token');
    $user = User::factory()->create();
    config()->set('downloads.extension_user_id', $user->id);

    $file = File::factory()->create([
        'referrer_url' => 'https://example.com/media/one.jpg',
        'url' => 'https://example.com/media/one.jpg',
        'downloaded' => true,
        'path' => 'downloads/original.jpg',
        'preview_path' => 'downloads/preview.jpg',
        'poster_path' => null,
    ]);

    Storage::disk('atlas-app')->put($file->path, 'original');
    Storage::disk('atlas-app')->put($file->preview_path, 'preview');

    $response = $this
        ->withHeader('X-Atlas-Extension-Token', 'test-token')
        ->postJson('/api/extension/files/react', [
            'type' => 'like',
            'url' => 'https://example.com/media/one.jpg',
            'original_url' => 'https://example.com/media/one.jpg',
            'source' => 'Extension',
            'force_download' => true,
        ]);

    $response->assertOk();

    $file->refresh();
    expect($file->downloaded)->toBeFalse();
    expect($file->path)->toBeNull();
    expect($file->preview_path)->toBeNull();

    Storage::disk('atlas-app')->assertMissing('downloads/original.jpg');
    Storage::disk('atlas-app')->assertMissing('downloads/preview.jpg');

    Queue::assertPushed(DownloadFile::class);
});

test('extension react can clear downloaded assets without forcing a re-download', function () {
    Queue::fake();
    Storage::fake('atlas-app');

    config()->set('downloads.extension_token', 'test-token');
    $user = User::factory()->create();
    config()->set('downloads.extension_user_id', $user->id);

    $file = File::factory()->create([
        'referrer_url' => 'https://example.com/media/clear.jpg',
        'url' => 'https://example.com/media/clear.jpg',
        'downloaded' => true,
        'path' => 'downloads/clear-original.jpg',
        'preview_path' => 'downloads/clear-preview.jpg',
    ]);

    Storage::disk('atlas-app')->put($file->path, 'original');
    Storage::disk('atlas-app')->put($file->preview_path, 'preview');

    $response = $this
        ->withHeader('X-Atlas-Extension-Token', 'test-token')
        ->postJson('/api/extension/files/react', [
            'type' => 'dislike',
            'url' => 'https://example.com/media/clear.jpg',
            'original_url' => 'https://example.com/media/clear.jpg',
            'source' => 'Extension',
            'clear_download' => true,
        ]);

    $response->assertOk();
    $response->assertJsonPath('reaction.type', 'dislike');

    $file->refresh();
    expect($file->downloaded)->toBeFalse();
    expect($file->path)->toBeNull();
    expect($file->preview_path)->toBeNull();

    Storage::disk('atlas-app')->assertMissing('downloads/clear-original.jpg');
    Storage::disk('atlas-app')->assertMissing('downloads/clear-preview.jpg');
    Queue::assertNotPushed(DownloadFile::class);
});

test('extension react can blacklist a file', function () {
    Queue::fake();

    config()->set('downloads.extension_token', 'test-token');
    $user = User::factory()->create();
    config()->set('downloads.extension_user_id', $user->id);

    $response = $this
        ->withHeader('X-Atlas-Extension-Token', 'test-token')
        ->postJson('/api/extension/files/react', [
            'type' => 'dislike',
            'url' => 'https://example.com/media/blacklist.jpg',
            'original_url' => 'https://example.com/media/blacklist.jpg',
            'source' => 'Extension',
            'blacklist' => true,
        ]);

    $response->assertOk();
    $response->assertJsonPath('reaction.type', 'dislike');
    $response->assertJsonPath('file.blacklist_type', 'manual');

    $file = File::query()->where('referrer_url', 'https://example.com/media/blacklist.jpg')->firstOrFail();
    expect($file->blacklisted_at)->not->toBeNull()
        ->and($file->blacklist_reason)->toBe('Extension blacklist');
});
