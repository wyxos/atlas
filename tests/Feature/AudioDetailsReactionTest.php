<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;

it('details returns per-user reaction booleans and persists after toggle', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $file = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    // Initially: no reaction -> all false in details
    $this->getJson("/audio/{$file->id}/details")
        ->assertOk()
        ->assertJsonFragment(['loved' => false, 'liked' => false, 'disliked' => false, 'funny' => false]);

    // Toggle like
    $this->postJson('/audio/'.$file->id.'/react', ['type' => 'like'])
        ->assertOk()
        ->assertJsonFragment(['liked' => true, 'loved' => false, 'disliked' => false, 'funny' => false]);

    // Reload details: should reflect liked
    $this->getJson("/audio/{$file->id}/details")
        ->assertOk()
        ->assertJsonFragment(['liked' => true, 'loved' => false, 'disliked' => false, 'funny' => false]);

    // Toggle off like (same click)
    $this->postJson('/audio/'.$file->id.'/react', ['type' => 'like'])
        ->assertOk()
        ->assertJsonFragment(['liked' => false]);

    // Details again: all false
    $this->getJson("/audio/{$file->id}/details")
        ->assertOk()
        ->assertJsonFragment(['loved' => false, 'liked' => false, 'disliked' => false, 'funny' => false]);
});
