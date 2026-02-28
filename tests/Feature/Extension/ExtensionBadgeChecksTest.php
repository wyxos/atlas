<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function setBadgeChecksExtensionApiKey(string $value): void
{
    DB::table('settings')->insert([
        'key' => 'extension.api_key_hash',
        'machine' => '',
        'value' => hash('sha256', $value),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('extension badge checks endpoint requires a valid api key', function () {
    setBadgeChecksExtensionApiKey('valid-api-key');

    $response = $this->postJson('/api/extension/badges/checks', [
        'items' => [
            ['request_id' => 'req-1', 'url' => 'https://example.test/media/a.jpg'],
        ],
    ]);

    $response->assertUnauthorized();
});

test('extension badge checks endpoint returns deterministic per-item status', function () {
    $user = User::factory()->create();
    config(['downloads.extension_user_id' => $user->id]);
    setBadgeChecksExtensionApiKey('valid-api-key');

    $matchedFile = File::factory()->create([
        'url' => 'https://cdn.example.test/media/full.jpg',
        'downloaded_at' => now()->subHour(),
        'blacklisted_at' => now()->subDay(),
    ]);

    Reaction::query()->create([
        'file_id' => $matchedFile->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/badges/checks', [
        'items' => [
            ['request_id' => 'req-1', 'url' => 'https://cdn.example.test/media/full.jpg#fragment'],
            ['request_id' => 'req-2', 'url' => 'https://cdn.example.test/other.jpg'],
            ['request_id' => 'req-3', 'url' => 'https://cdn.example.test/media/full.jpg'],
        ],
    ]);

    $response->assertSuccessful();
    $response->assertJsonCount(3, 'matches');

    $response->assertJsonPath('matches.0.request_id', 'req-1');
    $response->assertJsonPath('matches.0.request_index', 0);
    $response->assertJsonPath('matches.0.url', 'https://cdn.example.test/media/full.jpg');
    $response->assertJsonPath('matches.0.exists', true);
    $response->assertJsonPath('matches.0.reaction', 'like');
    $response->assertJsonPath('matches.0.downloaded_at', $matchedFile->downloaded_at?->toIso8601String());
    $response->assertJsonPath('matches.0.blacklisted_at', $matchedFile->blacklisted_at?->toIso8601String());

    $response->assertJsonPath('matches.1.request_id', 'req-2');
    $response->assertJsonPath('matches.1.request_index', 1);
    $response->assertJsonPath('matches.1.exists', false);
    $response->assertJsonPath('matches.1.reaction', null);
    $response->assertJsonPath('matches.1.downloaded_at', null);
    $response->assertJsonPath('matches.1.blacklisted_at', null);

    $response->assertJsonPath('matches.2.request_id', 'req-3');
    $response->assertJsonPath('matches.2.request_index', 2);
    $response->assertJsonPath('matches.2.url', 'https://cdn.example.test/media/full.jpg');
    $response->assertJsonPath('matches.2.exists', true);
    $response->assertJsonPath('matches.2.reaction', 'like');
});

test('extension badge checks performs batched queries for large request sets', function () {
    $user = User::factory()->create();
    config(['downloads.extension_user_id' => $user->id]);
    setBadgeChecksExtensionApiKey('valid-api-key');

    $matchedUrls = [];
    for ($index = 0; $index < 30; $index++) {
        $url = "https://cdn.example.test/media/{$index}.jpg";
        $matchedUrls[] = $url;
        File::factory()->create([
            'url' => $url,
            'downloaded_at' => now()->subMinutes($index + 1),
            'blacklisted_at' => null,
        ]);
    }

    Reaction::query()->create([
        'file_id' => File::query()->where('url', $matchedUrls[0])->value('id'),
        'user_id' => $user->id,
        'type' => 'love',
    ]);

    $items = [];
    for ($index = 0; $index < 60; $index++) {
        $items[] = [
            'request_id' => 'req-'.$index,
            'url' => $index < 30
                ? $matchedUrls[$index]
                : 'https://cdn.example.test/media/missing-'.$index.'.jpg',
        ];
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/badges/checks', [
        'items' => $items,
    ]);

    $response->assertSuccessful();
    $response->assertJsonCount(60, 'matches');

    $queryCount = count(DB::getQueryLog());
    expect($queryCount)->toBeLessThanOrEqual(10);
});
