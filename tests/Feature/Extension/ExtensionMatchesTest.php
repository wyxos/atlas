<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function setExtensionApiKey(string $value, ?int $userId = null): void
{
    DB::table('settings')->updateOrInsert([
        'key' => 'extension.api_key_hash',
        'machine' => '',
    ], [
        'value' => hash('sha256', $value),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($userId !== null) {
        DB::table('settings')->updateOrInsert([
            'key' => 'extension.api_key_user_id',
            'machine' => '',
        ], [
            'value' => (string) $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
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

test('extension referrer checks endpoint requires a valid api key', function () {
    setExtensionApiKey('valid-api-key');

    $hash = hash('sha256', 'https://www.deviantart.com/artist/art/sample-1');
    $response = $this->postJson('/api/extension/referrer-checks', [
        'items' => [
            ['request_id' => 'ref-1', 'referrer_hash' => $hash],
        ],
    ]);

    $response->assertUnauthorized();
});

test('extension matches endpoint returns status for matched and unmatched media', function () {
    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

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

test('extension referrer checks endpoint returns status for matched and unmatched referrer hashes', function () {
    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

    $referrerUrl = 'https://www.deviantart.com/artist/art/work-123';
    $file = File::factory()->create([
        'url' => 'https://cdn.example.test/media/full.jpg',
        'referrer_url' => $referrerUrl,
        'downloaded' => true,
        'downloaded_at' => now()->subHour(),
        'blacklisted_at' => now()->subDay(),
    ]);

    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'love',
    ]);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/referrer-checks', [
        'items' => [
            [
                'request_id' => 'ref-hit',
                'referrer_hash' => hash('sha256', $referrerUrl),
            ],
            [
                'request_id' => 'ref-miss',
                'referrer_hash' => hash('sha256', 'https://www.deviantart.com/artist/art/missing'),
            ],
        ],
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('matches.0.request_id', 'ref-hit');
    $response->assertJsonPath('matches.0.exists', true);
    $response->assertJsonPath('matches.0.reaction', 'love');
    $response->assertJsonPath('matches.1.request_id', 'ref-miss');
    $response->assertJsonPath('matches.1.exists', false);
});

test('extension referrer checks prefer the latest updated row for duplicate referrer hashes', function () {
    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

    $referrerUrl = 'https://www.deviantart.com/artist/art/duplicate-referrer';

    $olderRow = File::factory()->create([
        'url' => 'https://cdn.example.test/media/duplicate-referrer-older.jpg',
        'referrer_url' => $referrerUrl,
    ]);

    $newerRow = File::factory()->create([
        'url' => 'https://cdn.example.test/media/duplicate-referrer-newer.jpg',
        'referrer_url' => $referrerUrl,
    ]);

    $olderRow->updated_at = now()->addMinute();
    $olderRow->saveQuietly();

    Reaction::query()->create([
        'file_id' => $olderRow->id,
        'user_id' => $user->id,
        'type' => 'funny',
    ]);

    Reaction::query()->create([
        'file_id' => $newerRow->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/referrer-checks', [
        'items' => [
            [
                'request_id' => 'ref-duplicate',
                'referrer_hash' => hash('sha256', $referrerUrl),
            ],
        ],
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('matches.0.request_id', 'ref-duplicate');
    $response->assertJsonPath('matches.0.exists', true);
    $response->assertJsonPath('matches.0.reaction', 'funny');
});

test('extension matches prefers media url match over referrer fallback for same candidate', function () {
    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

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
        'type' => 'funny',
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
    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

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
