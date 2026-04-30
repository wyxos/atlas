<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('increments preview count without returning auto dislike state', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create(['previewed_count' => 0]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/preview");

    $response->assertSuccessful()
        ->assertJson([
            'previewed_count' => 1,
        ])
        ->assertJsonMissingPath('will_auto_dislike');

    $file->refresh();
    expect($file->previewed_count)->toBe(1)
        ->and($file->auto_disliked)->toBeFalse();
});
