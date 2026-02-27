<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated user can fetch cursor-paginated audio ids only', function () {
    $user = User::factory()->create();

    $audioOne = File::factory()->create(['mime_type' => 'audio/mpeg']);
    File::factory()->create(['mime_type' => 'image/jpeg']);
    $audioTwo = File::factory()->create(['mime_type' => 'audio/ogg']);
    $audioThree = File::factory()->create(['mime_type' => 'audio/wav']);

    $response = $this->actingAs($user)->getJson('/api/audio/ids?after_id=0&per_page=2');

    $response->assertSuccessful();
    $response->assertJson([
        'ids' => [$audioOne->id, $audioTwo->id],
        'cursor' => [
            'after_id' => 0,
            'next_after_id' => $audioTwo->id,
            'has_more' => true,
            'max_id' => $audioThree->id,
        ],
        'pagination' => [
            'per_page' => 2,
            'total' => 3,
            'total_pages' => 2,
        ],
    ]);
    $response->assertJsonPath('sources.'.$audioOne->id, $audioOne->source);
    $response->assertJsonPath('sources.'.$audioTwo->id, $audioTwo->source);

    $cursor = $response->json('cursor');

    $nextChunk = $this->actingAs($user)->getJson('/api/audio/ids?after_id='.$cursor['next_after_id'].'&max_id='.$cursor['max_id'].'&per_page=2');
    $nextChunk->assertSuccessful();
    $nextChunk->assertJson([
        'ids' => [$audioThree->id],
        'cursor' => [
            'after_id' => $audioTwo->id,
            'next_after_id' => null,
            'has_more' => false,
            'max_id' => $audioThree->id,
        ],
        'pagination' => [
            'per_page' => 2,
            'total' => null,
            'total_pages' => null,
        ],
    ]);
    $nextChunk->assertJsonPath('sources.'.$audioThree->id, $audioThree->source);
});

test('guest cannot fetch audio ids', function () {
    $response = $this->getJson('/api/audio/ids');

    $response->assertUnauthorized();
});

test('authenticated user can fetch audio details batch for ids', function () {
    $user = User::factory()->create();

    $audio = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'title' => null,
        'filename' => 'fallback-track.mp3',
        'source' => 'Spotify',
    ]);
    $audio->metadata()->create([
        'payload' => [
            'title' => 'Song Title',
            'artist' => ['Artist A', 'Artist B'],
            'album' => 'Album Name',
        ],
    ]);

    $response = $this->actingAs($user)->postJson('/api/audio/details', [
        'ids' => [$audio->id],
    ]);

    $response->assertSuccessful();
    $response->assertJson([
        'items' => [
            [
                'id' => $audio->id,
                'title' => 'Song Title',
                'source' => 'Spotify',
                'artists' => ['Artist A', 'Artist B'],
                'albums' => ['Album Name'],
            ],
        ],
    ]);
});

test('guest cannot fetch audio details', function () {
    $response = $this->postJson('/api/audio/details', [
        'ids' => [1],
    ]);

    $response->assertUnauthorized();
});
