<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;

beforeEach(function () {
    useTypesense();
    resetTypesenseFileCollection();
});

it('file appears in virtual playlists based on reactions via Scout queries', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Create an initially unrated audio file (no reactions)
    $file = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    // Reindex the file in Typesense with empty reacted_user_ids
    $file->searchable();

    // File should have no reactions initially
    expect(Reaction::where('file_id', $file->id)->where('user_id', $user->id)->exists())->toBeFalse();

    // React: like the file
    $this->postJson('/audio/'.$file->id.'/react', ['type' => 'like'])
        ->assertOk();

    // Verify reaction was created in database
    $reaction = Reaction::where('file_id', $file->id)->where('user_id', $user->id)->first();
    expect($reaction)->not->toBeNull()
        ->and($reaction->type)->toBe('like');

    // Refresh the file model and verify it has the reaction in reacted_user_ids
    $file->refresh();
    $file->searchable(); // Reindex with updated reactions

    // The file should now appear in the liked route via Scout query
    $response = $this->getJson('/audio/liked/data');
    $response->assertOk();
    $audioIds = $response->json('playlistFileIds');
    expect($audioIds)->toBeArray()
        ->and($audioIds)->toContain($file->id);

    // The file should NOT appear in the unrated route
    $response = $this->getJson('/audio/unrated/data');
    $response->assertOk();
    $audioIds = $response->json('playlistFileIds');
    expect($audioIds)->toBeArray()
        ->and($audioIds)->not->toContain($file->id);
});
