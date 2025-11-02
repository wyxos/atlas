<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;

beforeEach(function () {
    useTypesense();
    resetTypesenseFileCollection();
});

test('liked page renders and shows liked tracks count', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Create some audio files and mark some as liked
    $f1 = File::factory()->create([
        'filename' => 'l1.mp3',
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);
    $f2 = File::factory()->create([
        'filename' => 'l2.mp3',
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);
    $f3 = File::factory()->create([
        'filename' => 'u1.mp3',
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    // Create like reactions for f1 and f2
    Reaction::factory()->create(['user_id' => $user->id, 'file_id' => $f1->id, 'type' => 'like']);
    Reaction::factory()->create(['user_id' => $user->id, 'file_id' => $f2->id, 'type' => 'like']);

    // Reindex files in Typesense
    $f1->refresh()->searchable();
    $f2->refresh()->searchable();
    $f3->searchable();

    $response = visit(route('audio.reactions', ['type' => 'liked']));
    $response->assertNoSmoke();
    $response->assertSee('Audio Library');
    $response->assertSee('tracks');
});

test('sidebar shows Liked and can navigate', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = visit(route('dashboard'));
    $response->assertNoSmoke();

    // New sidebar: expand Playlists group, then click Liked
    $response->click('Playlists')
        ->assertSee('Liked');

    // Click the Liked link in the sidebar
    $response->click('Liked')
        ->assertUrlIs(route('audio.reactions', ['type' => 'liked']))
        ->assertNoSmoke()
        ->assertSee('Audio Library');
});
