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
            ['id' => 'atlas-1', 'media_url' => 'https://example.test/a.jpg'],
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
                'id' => 'atlas-1',
                'media_url' => 'https://cdn.example.test/media/preview.jpg',
                'anchor_url' => 'https://www.deviantart.com/artist/art/work-123',
                'page_url' => 'https://www.deviantart.com/',
            ],
            [
                'id' => 'atlas-2',
                'media_url' => 'https://cdn.example.test/other.jpg',
                'anchor_url' => 'https://www.deviantart.com/artist/art/other',
                'page_url' => 'https://www.deviantart.com/',
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
