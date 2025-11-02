<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Support\Facades\Config;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Config::set('scout.driver', 'collection');
    Config::set('scout.queue', false);
    Config::set('scout.after_commit', false);
});

it('shows local files regardless of reactions and non-local files only with reactions', function () {
    $user = User::factory()->create();
    actingAs($user);

    // Non-local with reactions
    $remoteReacted = File::factory()->create([
        'source' => 'reddit',
        'mime_type' => 'image/jpeg',
        'url' => 'https://cdn.example.com/remote-reacted.jpg',
        'thumbnail_url' => 'https://cdn.example.com/remote-reacted-thumb.jpg',
    ]);
    Reaction::factory()->create([
        'file_id' => $remoteReacted->id,
        'user_id' => $user->id,
        'type' => 'love',
    ]);

    // Non-local without reactions
    $remoteUnreacted = File::factory()->create([
        'source' => 'twitter',
        'mime_type' => 'image/png',
        'url' => 'https://cdn.example.com/remote-unreacted.png',
        'thumbnail_url' => 'https://cdn.example.com/remote-unreacted-thumb.png',
    ]);

    // Local with reactions
    $localReacted = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'image/jpeg',
        'path' => 'photos/local-reacted.jpg',
        'thumbnail_url' => 'https://cdn.example.com/local-reacted-thumb.jpg',
    ]);
    Reaction::factory()->create([
        'file_id' => $localReacted->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    // Local without reactions
    $localUnreacted = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'image/png',
        'path' => 'photos/local-unreacted.png',
        'thumbnail_url' => 'https://cdn.example.com/local-unreacted-thumb.png',
    ]);

    // Manually provide Scout results including all files
    withFakeScoutResults([$remoteReacted, $remoteUnreacted, $localReacted, $localUnreacted], function () use ($remoteReacted, $localReacted, $localUnreacted, $remoteUnreacted) {
        $response = $this->getJson(route('photos.data', ['limit' => 50]));

        $response->assertStatus(200);

        $fileIds = collect($response->json('files'))->pluck('id')->all();

        // Should include: remote reacted, local reacted, local unreacted
        expect($fileIds)->toContain($remoteReacted->id);
        expect($fileIds)->toContain($localReacted->id);
        expect($fileIds)->toContain($localUnreacted->id);

        // Should exclude: remote unreacted
        expect($fileIds)->not->toContain($remoteUnreacted->id);
    });
});

it('shows only local files when filtered by source local', function () {
    $user = User::factory()->create();
    actingAs($user);

    // Remote file
    $remote = File::factory()->create([
        'source' => 'reddit',
        'mime_type' => 'image/jpeg',
        'url' => 'https://cdn.example.com/remote.jpg',
        'thumbnail_url' => 'https://cdn.example.com/remote-thumb.jpg',
    ]);
    Reaction::factory()->create([
        'file_id' => $remote->id,
        'user_id' => $user->id,
        'type' => 'love',
    ]);

    // Local files
    $localReacted = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'image/jpeg',
        'path' => 'photos/local-1.jpg',
        'thumbnail_url' => 'https://cdn.example.com/local-1-thumb.jpg',
    ]);
    Reaction::factory()->create([
        'file_id' => $localReacted->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    $localUnreacted = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'image/png',
        'path' => 'photos/local-2.png',
        'thumbnail_url' => 'https://cdn.example.com/local-2-thumb.png',
    ]);

    // Manually provide Scout results - when filtering by source=local
    withFakeScoutResults([$localReacted, $localUnreacted], function () use ($localReacted, $localUnreacted, $remote) {
        $response = $this->getJson(route('photos.data', ['limit' => 50, 'source' => 'local']));

        $response->assertStatus(200);

        $fileIds = collect($response->json('files'))->pluck('id')->all();

        // Should only include local files (both reacted and unreacted)
        expect($fileIds)->toContain($localReacted->id);
        expect($fileIds)->toContain($localUnreacted->id);

        // Should exclude remote files
        expect($fileIds)->not->toContain($remote->id);
    });
});

it('shows only non-local reacted files when filtered by non-local source', function () {
    $user = User::factory()->create();
    actingAs($user);

    // Local file
    $local = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'image/jpeg',
        'path' => 'photos/local.jpg',
        'thumbnail_url' => 'https://cdn.example.com/local-thumb.jpg',
    ]);

    // Remote reacted
    $remoteReacted = File::factory()->create([
        'source' => 'reddit',
        'mime_type' => 'image/jpeg',
        'url' => 'https://cdn.example.com/reddit-reacted.jpg',
        'thumbnail_url' => 'https://cdn.example.com/reddit-reacted-thumb.jpg',
    ]);
    Reaction::factory()->create([
        'file_id' => $remoteReacted->id,
        'user_id' => $user->id,
        'type' => 'love',
    ]);

    // Remote unreacted from same source
    $remoteUnreacted = File::factory()->create([
        'source' => 'reddit',
        'mime_type' => 'image/png',
        'url' => 'https://cdn.example.com/reddit-unreacted.png',
        'thumbnail_url' => 'https://cdn.example.com/reddit-unreacted-thumb.png',
    ]);

    // Manually provide Scout results - when filtering by source=reddit
    withFakeScoutResults([$remoteReacted], function () use ($remoteReacted, $local, $remoteUnreacted) {
        $response = $this->getJson(route('photos.data', ['limit' => 50, 'source' => 'reddit']));

        $response->assertStatus(200);

        $fileIds = collect($response->json('files'))->pluck('id')->all();

        // Should only include reddit reacted file
        expect($fileIds)->toContain($remoteReacted->id);

        // Should exclude local and unreacted reddit file
        expect($fileIds)->not->toContain($local->id);
        expect($fileIds)->not->toContain($remoteUnreacted->id);
    });
});
