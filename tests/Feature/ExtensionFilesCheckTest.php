<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('checks whether external files exist by url', function () {
    config()->set('downloads.extension_token', 'test-token');
    $user = User::factory()->create();
    config()->set('downloads.extension_user_id', $user->id);

    $file = File::factory()->create([
        'url' => 'https://example.com/media/one.jpg',
        'referrer_url' => 'https://example.com/media/one.jpg',
        'downloaded' => true,
    ]);

    \App\Models\Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    $response = $this
        ->withHeader('X-Atlas-Extension-Token', 'test-token')
        ->postJson('/api/extension/files/check', [
            'urls' => [
                'https://example.com/media/one.jpg',
                'https://example.com/media/two.jpg',
            ],
        ]);

    $response->assertOk();
    $response->assertJson([
        'results' => [
            [
                'url' => 'https://example.com/media/one.jpg',
                'exists' => true,
                'downloaded' => true,
                'reaction' => ['type' => 'like'],
            ],
            [
                'url' => 'https://example.com/media/two.jpg',
                'exists' => false,
                'downloaded' => false,
                'file_id' => null,
            ],
        ],
    ]);
});
