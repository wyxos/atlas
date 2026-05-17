<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/BrowseIndexTestSupport.php';

uses(RefreshDatabase::class);

test('browse excludes files already marked as not found', function () {

    $user = User::factory()->create();

    File::factory()->create([

        'source' => 'CivitAI',

        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/not-found-guid/original=true/not-found-guid.jpeg',

        'preview_url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/not-found-guid/width=1216/not-found-guid.jpeg',

        'not_found' => true,

        'path' => null,

        'preview_path' => null,

        'downloaded' => false,

    ]);

    Http::fake([

        'https://civitai.com/api/v1/images*' => Http::response([

            'items' => [[

                'id' => 999,

                'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/not-found-guid/original=true/not-found-guid.jpeg',

                'thumbnailUrl' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/not-found-guid/width=1216/not-found-guid.jpeg',

                'width' => 512,

                'height' => 768,

                'mimeType' => 'image/jpeg',

            ]],

            'metadata' => [

                'nextCursor' => null,

            ],

        ], 200),

    ]);

    $response = $this->actingAs($user)->getJson('/api/browse?service=civit-ai-images');

    $response->assertSuccessful();

    expect($response->json('items'))->toBe([]);

});

test('online browse excludes Auto blacklisted files and current user reacted files', function () {

    $user = User::factory()->create();

    $otherUser = User::factory()->create();

    $AutoBlacklisted = File::factory()->create([

        'source' => 'CivitAI',

        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/auto-blacklisted-guid/original=true/auto-blacklisted-guid.jpeg',

        'preview_url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/auto-blacklisted-guid/width=1216/auto-blacklisted-guid.jpeg',

        'auto_blacklisted' => true,

        'downloaded' => false,

    ]);

    $currentUserReacted = File::factory()->create([

        'source' => 'CivitAI',

        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/current-user-reacted-guid/original=true/current-user-reacted-guid.jpeg',

        'preview_url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/current-user-reacted-guid/width=1216/current-user-reacted-guid.jpeg',

        'auto_blacklisted' => false,

        'downloaded' => false,

    ]);

    $otherUserReacted = File::factory()->create([

        'source' => 'CivitAI',

        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/other-user-reacted-guid/original=true/other-user-reacted-guid.jpeg',

        'preview_url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/other-user-reacted-guid/width=1216/other-user-reacted-guid.jpeg',

        'auto_blacklisted' => false,

        'downloaded' => false,

    ]);

    $visible = File::factory()->create([

        'source' => 'CivitAI',

        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/visible-guid/original=true/visible-guid.jpeg',

        'preview_url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/visible-guid/width=1216/visible-guid.jpeg',

        'auto_blacklisted' => false,

        'downloaded' => false,

    ]);

    Reaction::query()->create([

        'file_id' => $currentUserReacted->id,

        'user_id' => $user->id,

        'type' => 'like',

    ]);

    Reaction::query()->create([

        'file_id' => $otherUserReacted->id,

        'user_id' => $otherUser->id,

        'type' => 'like',

    ]);

    Http::fake([

        'https://civitai.com/api/v1/images*' => Http::response([

            'items' => [

                [

                    'id' => 1001,

                    'url' => $AutoBlacklisted->url,

                    'thumbnailUrl' => $AutoBlacklisted->preview_url,

                    'width' => 512,

                    'height' => 768,

                    'mimeType' => 'image/jpeg',

                ],

                [

                    'id' => 1002,

                    'url' => $currentUserReacted->url,

                    'thumbnailUrl' => $currentUserReacted->preview_url,

                    'width' => 512,

                    'height' => 768,

                    'mimeType' => 'image/jpeg',

                ],

                [

                    'id' => 1003,

                    'url' => $otherUserReacted->url,

                    'thumbnailUrl' => $otherUserReacted->preview_url,

                    'width' => 512,

                    'height' => 768,

                    'mimeType' => 'image/jpeg',

                ],

                [

                    'id' => 1004,

                    'url' => $visible->url,

                    'thumbnailUrl' => $visible->preview_url,

                    'width' => 512,

                    'height' => 768,

                    'mimeType' => 'image/jpeg',

                ],

            ],

            'metadata' => [

                'nextCursor' => null,

            ],

        ], 200),

    ]);

    $response = $this->actingAs($user)->getJson('/api/browse?service=civit-ai-images');

    $response->assertSuccessful();

    expect(collect($response->json('items'))->pluck('id')->all())

        ->toBe([$otherUserReacted->id, $visible->id]);

});
