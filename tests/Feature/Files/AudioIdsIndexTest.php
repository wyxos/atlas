<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('authenticated user can fetch cursor-paginated audio ids only', function () {
    $user = User::factory()->create();
    $queries = [];

    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

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
    expect($queries)->toContainAudioIdPageIndexHint();

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

expect()->extend('toContainAudioIdPageIndexHint', function () {
    $containsIndexHint = collect($this->value)->contains(
        static fn (string $sql): bool => str_contains($sql, 'indexed by files_mime_type_id_index')
            || str_contains($sql, 'force index (files_mime_type_id_index)')
    );

    expect($containsIndexHint)->toBeTrue();
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
        'poster_path' => 'imports/ab/cd/poster.jpg',
        'previewed_count' => 12,
        'seen_count' => 4,
    ]);
    $audio->metadata()->create([
        'payload' => [
            'title' => 'Song Title',
            'artist' => ['Artist A', 'Artist B'],
            'album' => 'Album Name',
            'duration_seconds' => 215,
        ],
    ]);
    Reaction::query()->create([
        'file_id' => $audio->id,
        'user_id' => $user->id,
        'type' => 'like',
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
                'cover_url' => "/api/files/{$audio->id}/poster",
                'duration_seconds' => 215,
                'reaction' => ['type' => 'like'],
                'blacklisted_at' => null,
                'previewed_count' => 12,
                'seen_count' => 4,
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
