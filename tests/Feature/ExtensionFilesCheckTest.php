<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('checks whether external files exist by canonical file url', function () {
    config()->set('downloads.extension_token', 'test-token');
    $user = User::factory()->create();
    config()->set('downloads.extension_user_id', $user->id);

    $file = File::factory()->create([
        'url' => 'https://images.example.com/media/direct.jpg',
        'referrer_url' => 'https://example.com/art/direct',
        'downloaded' => true,
    ]);

    \App\Models\Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'love',
    ]);

    $response = $this
        ->withHeader('X-Atlas-Extension-Token', 'test-token')
        ->postJson('/api/extension/files/check', [
            'urls' => [
                'https://images.example.com/media/direct.jpg',
                'https://images.example.com/media/missing.jpg',
            ],
        ]);

    $response->assertOk();
    $response->assertJsonPath('results.0.url', 'https://images.example.com/media/direct.jpg');
    $response->assertJsonPath('results.0.exists', true);
    $response->assertJsonPath('results.0.downloaded', true);
    $response->assertJsonPath('results.0.reaction.type', 'love');
    $response->assertJsonPath('results.1.url', 'https://images.example.com/media/missing.jpg');
    $response->assertJsonPath('results.1.exists', false);
    $response->assertJsonPath('results.1.downloaded', false);
});

it('checks whether external files exist by referrer page url', function () {
    config()->set('downloads.extension_token', 'test-token');
    $user = User::factory()->create();
    config()->set('downloads.extension_user_id', $user->id);

    $file = File::factory()->create([
        'url' => 'https://example.com/media/one.jpg',
        'referrer_url' => 'https://example.com/art/one',
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
                'https://example.com/art/one',
                'https://example.com/art/two',
            ],
        ]);

    $response->assertOk();
    $response->assertJson([
        'results' => [
            [
                'url' => 'https://example.com/art/one',
                'exists' => true,
                'downloaded' => true,
                'reaction' => ['type' => 'like'],
            ],
            [
                'url' => 'https://example.com/art/two',
                'exists' => false,
                'downloaded' => false,
                'file_id' => null,
            ],
        ],
    ]);
});

it('checks whether external files exist by referrer url', function () {
    config()->set('downloads.extension_token', 'test-token');
    $user = User::factory()->create();
    config()->set('downloads.extension_user_id', $user->id);

    $file = File::factory()->create([
        'url' => 'https://images.example.com/media/one-full.jpg',
        'referrer_url' => 'https://example.com/art/one',
        'downloaded' => true,
    ]);

    \App\Models\Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'love',
    ]);

    $response = $this
        ->withHeader('X-Atlas-Extension-Token', 'test-token')
        ->postJson('/api/extension/files/check', [
            'urls' => [
                'https://example.com/art/one',
            ],
        ]);

    $response->assertOk();
    $response->assertJson([
        'results' => [
            [
                'url' => 'https://example.com/art/one',
                'exists' => true,
                'downloaded' => true,
                'reaction' => ['type' => 'love'],
            ],
        ],
    ]);
});

it('aggregates status when multiple files share the same referrer url', function () {
    config()->set('downloads.extension_token', 'test-token');
    $user = User::factory()->create();
    config()->set('downloads.extension_user_id', $user->id);

    $first = File::factory()->create([
        'url' => 'https://images.example.com/media/a.jpg',
        'referrer_url' => 'https://example.com/art/shared',
        'downloaded' => false,
    ]);
    $second = File::factory()->create([
        'url' => 'https://images.example.com/media/b.jpg',
        'referrer_url' => 'https://example.com/art/shared',
        'downloaded' => true,
    ]);

    \App\Models\Reaction::query()->create([
        'file_id' => $first->id,
        'user_id' => $user->id,
        'type' => 'funny',
    ]);

    $response = $this
        ->withHeader('X-Atlas-Extension-Token', 'test-token')
        ->postJson('/api/extension/files/check', [
            'urls' => ['https://example.com/art/shared'],
        ]);

    $response->assertOk();
    $response->assertJsonPath('results.0.exists', true);
    $response->assertJsonPath('results.0.downloaded', true);
    $response->assertJsonPath('results.0.reaction.type', 'funny');
});

it('does not leak reaction state across files when checking by media urls', function () {
    config()->set('downloads.extension_token', 'test-token');
    $user = User::factory()->create();
    config()->set('downloads.extension_user_id', $user->id);

    $first = File::factory()->create([
        'url' => 'https://images.example.com/media/first.jpg',
        'referrer_url' => 'https://example.com/art/shared-page',
        'downloaded' => true,
    ]);
    $second = File::factory()->create([
        'url' => 'https://images.example.com/media/second.jpg',
        'referrer_url' => 'https://example.com/art/shared-page',
        'downloaded' => false,
    ]);

    \App\Models\Reaction::query()->create([
        'file_id' => $first->id,
        'user_id' => $user->id,
        'type' => 'love',
    ]);

    $response = $this
        ->withHeader('X-Atlas-Extension-Token', 'test-token')
        ->postJson('/api/extension/files/check', [
            'urls' => [
                'https://images.example.com/media/first.jpg',
                'https://images.example.com/media/second.jpg',
            ],
        ]);

    $response->assertOk();
    $response->assertJsonPath('results.0.url', 'https://images.example.com/media/first.jpg');
    $response->assertJsonPath('results.0.exists', true);
    $response->assertJsonPath('results.0.downloaded', true);
    $response->assertJsonPath('results.0.reaction.type', 'love');

    $response->assertJsonPath('results.1.url', 'https://images.example.com/media/second.jpg');
    $response->assertJsonPath('results.1.exists', true);
    $response->assertJsonPath('results.1.downloaded', false);
    $response->assertJsonPath('results.1.reaction', null);
});
