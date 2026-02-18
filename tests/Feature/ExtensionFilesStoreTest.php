<?php

use App\Jobs\DownloadFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('extension store requires reaction_type', function () {
    config()->set('downloads.extension_token', 'test-token');

    $response = $this
        ->withHeader('X-Atlas-Extension-Token', 'test-token')
        ->postJson('/api/extension/files', [
            'url' => 'https://example.com/media/one.jpg',
        ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['reaction_type']);
});

test('extension store drives download through reaction pipeline', function () {
    Queue::fake();

    config()->set('downloads.extension_token', 'test-token');
    $user = User::factory()->create();
    config()->set('downloads.extension_user_id', $user->id);

    $response = $this
        ->withHeader('X-Atlas-Extension-Token', 'test-token')
        ->postJson('/api/extension/files', [
            'url' => 'https://example.com/media/one.jpg',
            'original_url' => 'https://example.com/media/one.jpg',
            'reaction_type' => 'like',
            'source' => 'Extension',
        ]);

    $response->assertCreated();
    $response->assertJsonPath('reaction.type', 'like');
    $response->assertJsonPath('queued', true);
    $response->assertJsonPath('file.original_url', 'https://example.com/media/one.jpg');
    $response->assertJsonPath('file.referrer_url', 'https://example.com/media/one.jpg');

    Queue::assertPushed(DownloadFile::class);
});
