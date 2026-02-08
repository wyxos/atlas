<?php

use App\Jobs\DownloadFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

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
