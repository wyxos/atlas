<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

it('requires authentication to react to a file', function () {
    $file = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $this->post('/audio/'.$file->id.'/react', ['type' => 'love'])
        ->assertRedirect('/login');
});

it('toggles love reaction and resets other flags', function () {
    Storage::fake('atlas');

    $file = File::factory()->create([
        'path' => 'media/a1.mp3',
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $user = User::factory()->create();

    // Toggle on
    $this->actingAs($user)
        ->postJson('/audio/'.$file->id.'/react', ['type' => 'love'])
        ->assertOk()
        ->assertJsonFragment(['loved' => true, 'liked' => false, 'disliked' => false, 'funny' => false]);

    // DB reaction should exist
    $r = \App\Models\Reaction::where('file_id', $file->id)->where('user_id', $user->id)->first();
    expect($r)->not->toBeNull()->and($r->type)->toBe('love');

    // Toggle off
    $this->actingAs($user)
        ->postJson('/audio/'.$file->id.'/react', ['type' => 'love'])
        ->assertOk()
        ->assertJsonFragment(['loved' => false]);

    expect(\App\Models\Reaction::where('file_id', $file->id)->where('user_id', $user->id)->exists())->toBeFalse();
});

it('enforces mutual exclusivity between reactions', function () {
    $file = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $user = User::factory()->create();

    // Like it
    $this->actingAs($user)
        ->postJson('/audio/'.$file->id.'/react', ['type' => 'like'])
        ->assertOk();

    $r = \App\Models\Reaction::where('file_id', $file->id)->where('user_id', $user->id)->first();
    expect($r)->not->toBeNull()->and($r->type)->toBe('like');

    // Dislike should replace like
    $this->actingAs($user)
        ->postJson('/audio/'.$file->id.'/react', ['type' => 'dislike'])
        ->assertOk();

    $r = \App\Models\Reaction::where('file_id', $file->id)->where('user_id', $user->id)->first();
    expect($r)->not->toBeNull()->and($r->type)->toBe('dislike');
});
