<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function setExtensionApiKey(string $value): void
{
    DB::table('settings')->insert([
        'key' => 'extension.api_key_hash',
        'machine' => '',
        'value' => hash('sha256', $value),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('extension matches endpoint requires a valid api key', function () {
    setExtensionApiKey('valid-api-key');

    $response = $this->postJson('/api/extension/matches', [
        'items' => [
            ['candidate_id' => 'atlas-1', 'type' => 'media', 'url' => 'https://example.test/a.jpg'],
        ],
    ]);

    $response->assertUnauthorized();
});

test('extension matches endpoint returns status for matched and unmatched media', function () {
    $user = User::factory()->create();
    config(['downloads.extension_user_id' => $user->id]);
    setExtensionApiKey('valid-api-key');

    $file = File::factory()->create([
        'url' => 'https://cdn.example.test/media/full.jpg',
        'referrer_url' => 'https://www.deviantart.com/artist/art/work-123',
        'preview_url' => 'https://cdn.example.test/media/preview.jpg',
        'downloaded' => true,
        'downloaded_at' => now()->subHour(),
        'blacklisted_at' => now()->subDay(),
    ]);

    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/matches', [
        'items' => [
            [
                'candidate_id' => 'atlas-1',
                'type' => 'media',
                'url' => 'https://cdn.example.test/media/full.jpg',
            ],
            [
                'candidate_id' => 'atlas-1',
                'type' => 'referrer',
                'url' => 'https://www.deviantart.com/artist/art/work-123',
            ],
            [
                'candidate_id' => 'atlas-2',
                'type' => 'media',
                'url' => 'https://cdn.example.test/other.jpg',
            ],
            [
                'candidate_id' => 'atlas-2',
                'type' => 'referrer',
                'url' => 'https://www.deviantart.com/artist/art/other',
            ],
        ],
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('matches.0.id', 'atlas-1');
    $response->assertJsonPath('matches.0.exists', true);
    $response->assertJsonPath('matches.0.reaction', 'like');
    $response->assertJsonPath('matches.1.id', 'atlas-2');
    $response->assertJsonPath('matches.1.exists', false);
});

test('extension matches prefers media url match over referrer fallback for same candidate', function () {
    $user = User::factory()->create();
    config(['downloads.extension_user_id' => $user->id]);
    setExtensionApiKey('valid-api-key');

    $mediaMatch = File::factory()->create([
        'url' => 'https://images-wixmp.com/f/media-priority.jpg',
        'referrer_url' => 'https://www.deviantart.com/artist/art/media-priority',
    ]);

    $referrerMatch = File::factory()->create([
        'url' => 'https://images-wixmp.com/f/other.jpg',
        'referrer_url' => 'https://www.deviantart.com/artist/art/referrer-priority',
    ]);

    Reaction::query()->create([
        'file_id' => $mediaMatch->id,
        'user_id' => $user->id,
        'type' => 'love',
    ]);

    Reaction::query()->create([
        'file_id' => $referrerMatch->id,
        'user_id' => $user->id,
        'type' => 'dislike',
    ]);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/matches', [
        'items' => [
            [
                'candidate_id' => 'atlas-priority',
                'type' => 'media',
                'url' => 'https://images-wixmp.com/f/media-priority.jpg',
            ],
            [
                'candidate_id' => 'atlas-priority',
                'type' => 'referrer',
                'url' => 'https://www.deviantart.com/artist/art/referrer-priority',
            ],
        ],
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('matches.0.id', 'atlas-priority');
    $response->assertJsonPath('matches.0.exists', true);
    $response->assertJsonPath('matches.0.reaction', 'love');
});

test('extension matches resolves legacy rows without hash columns via exact url fallback', function () {
    setExtensionApiKey('valid-api-key');

    $file = File::factory()->create([
        'url' => 'https://images-wixmp.com/f/legacy-null-hash.jpg',
        'referrer_url' => 'https://www.deviantart.com/artist/art/legacy-null-hash',
    ]);

    $file->url_hash = null;
    $file->referrer_url_hash = null;
    $file->saveQuietly();

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/matches', [
        'items' => [
            [
                'candidate_id' => 'atlas-legacy',
                'type' => 'media',
                'url' => 'https://images-wixmp.com/f/legacy-null-hash.jpg',
            ],
        ],
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('matches.0.id', 'atlas-legacy');
    $response->assertJsonPath('matches.0.exists', true);
});
