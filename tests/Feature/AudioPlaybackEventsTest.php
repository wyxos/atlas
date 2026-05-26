<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated users can record audio play and skip events', function () {
    $user = User::factory()->create();
    $audio = File::factory()->create(['mime_type' => 'audio/mpeg']);

    $this->actingAs($user)
        ->postJson('/api/audio/playback-events', [
            'event' => 'play',
            'file_id' => $audio->id,
        ])
        ->assertSuccessful()
        ->assertJsonPath('file_id', $audio->id)
        ->assertJsonPath('play_count', 1)
        ->assertJsonPath('skip_count', 0);

    $this->actingAs($user)
        ->postJson('/api/audio/playback-events', [
            'event' => 'skip',
            'file_id' => $audio->id,
        ])
        ->assertSuccessful()
        ->assertJsonPath('file_id', $audio->id)
        ->assertJsonPath('play_count', 1)
        ->assertJsonPath('skip_count', 1);
});

test('audio playback event validation rejects guests, non audio files, and invalid events', function () {
    $user = User::factory()->create();
    $image = File::factory()->create(['mime_type' => 'image/jpeg']);

    $this->postJson('/api/audio/playback-events', [
        'event' => 'play',
        'file_id' => $image->id,
    ])->assertUnauthorized();

    $this->actingAs($user)
        ->postJson('/api/audio/playback-events', [
            'event' => 'play',
            'file_id' => $image->id,
        ])
        ->assertUnprocessable();

    $this->actingAs($user)
        ->postJson('/api/audio/playback-events', [
            'event' => 'replay',
            'file_id' => $image->id,
        ])
        ->assertUnprocessable();
});
