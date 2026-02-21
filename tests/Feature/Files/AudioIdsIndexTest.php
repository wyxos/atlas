<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated user can fetch paginated audio ids only', function () {
    $user = User::factory()->create();

    $audioOne = File::factory()->create(['mime_type' => 'audio/mpeg']);
    File::factory()->create(['mime_type' => 'image/jpeg']);
    $audioTwo = File::factory()->create(['mime_type' => 'audio/ogg']);
    $audioThree = File::factory()->create(['mime_type' => 'audio/wav']);

    $response = $this->actingAs($user)->getJson('/api/audio/ids?page=1&per_page=2');

    $response->assertSuccessful();
    $response->assertJson([
        'ids' => [$audioOne->id, $audioTwo->id],
        'pagination' => [
            'page' => 1,
            'per_page' => 2,
            'total' => 3,
            'total_pages' => 2,
        ],
    ]);

    $pageTwo = $this->actingAs($user)->getJson('/api/audio/ids?page=2&per_page=2');
    $pageTwo->assertSuccessful();
    $pageTwo->assertJson([
        'ids' => [$audioThree->id],
        'pagination' => [
            'page' => 2,
            'per_page' => 2,
            'total' => 3,
            'total_pages' => 2,
        ],
    ]);
});

test('guest cannot fetch audio ids', function () {
    $response = $this->getJson('/api/audio/ids');

    $response->assertUnauthorized();
});
