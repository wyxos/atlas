<?php

use App\Models\File;
use App\Models\User;

it('returns all loved audio files across pages (>250)', function () {
    useTypesense();

    $user = User::factory()->create();
    $this->actingAs($user);

    // Create > 250 audio files and mark as loved for this user
    $count = 300;
    File::factory()->count($count)->create([
        'mime_type' => 'audio/mpeg',
        'blacklisted_at' => null,
        'not_found' => false,
    ])->each(function (File $f) use ($user) {
        // Set love reaction via relationship to ensure it appears in Typesense arrays when indexed
        $f->reactions()->create(['user_id' => $user->id, 'type' => 'love']);
    });

    // Ensure index reflects current data
    File::query()->searchable();

    // Hit JSON data endpoint for favorites
    $resp = $this->getJson('/audio/favorites/data');
    $resp->assertOk();

    $payload = $resp->json();
    expect($payload)
        ->toHaveKeys(['playlistFileIds', 'files'])
        ->and(count($payload['playlistFileIds']))->toBeGreaterThanOrEqual($count)
        ->and(count($payload['files']))->toBeGreaterThanOrEqual($count);
});
