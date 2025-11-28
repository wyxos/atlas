<?php

use App\Models\File;
use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('returns a list of files for admin users', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    File::factory()->count(5)->create();

    $this->actingAs($admin)
        ->getJson('/api/files')
        ->assertSuccessful()
        ->assertJsonStructure([
            'listing' => [
                'items' => [
                    '*' => [
                        'id',
                        'source',
                        'filename',
                        'ext',
                        'size',
                        'mime_type',
                        'title',
                        'url',
                        'path',
                        'thumbnail_url',
                        'downloaded',
                        'not_found',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'total',
                'perPage',
                'current_page',
                'last_page',
                'from',
                'to',
            ],
            'links',
            'filters',
        ]);
});

it('prevents non-admin users from accessing files', function () {
    $user = User::factory()->create(['is_admin' => false]);
    File::factory()->count(5)->create();

    $this->actingAs($user)
        ->getJson('/api/files')
        ->assertForbidden();
});

it('requires authentication to access files', function () {
    $this->getJson('/api/files')
        ->assertUnauthorized();
});

it('returns files ordered by updated_at descending', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $oldFile = File::factory()->create(['updated_at' => '2024-01-01 10:00:00']);
    $newFile = File::factory()->create(['updated_at' => '2024-01-03 10:00:00']);
    $middleFile = File::factory()->create(['updated_at' => '2024-01-02 10:00:00']);

    $response = $this->actingAs($admin)
        ->getJson('/api/files');

    $response->assertSuccessful();
    $files = $response->json('listing.items');
    expect($files[0]['id'])->toBe($newFile->id);
    expect($files[1]['id'])->toBe($middleFile->id);
    expect($files[2]['id'])->toBe($oldFile->id);
});

it('supports pagination', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    File::factory()->count(20)->create();

    $response = $this->actingAs($admin)
        ->getJson('/api/files?page=1&per_page=15');

    $response->assertSuccessful();
    expect($response->json('listing.items'))->toHaveCount(15);
    expect($response->json('listing.current_page'))->toBe(1);
    expect($response->json('listing.total'))->toBeGreaterThanOrEqual(20);
});

it('allows admin to delete files', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $fileToDelete = File::factory()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/files/{$fileToDelete->id}")
        ->assertSuccessful()
        ->assertJson([
            'message' => 'File deleted successfully.',
        ]);

    expect(File::find($fileToDelete->id))->toBeNull();
});

it('prevents non-admin users from deleting files', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $fileToDelete = File::factory()->create();

    $this->actingAs($user)
        ->deleteJson("/api/files/{$fileToDelete->id}")
        ->assertForbidden();

    expect(File::find($fileToDelete->id))->not->toBeNull();
});

it('requires authentication to delete files', function () {
    $fileToDelete = File::factory()->create();

    $this->deleteJson("/api/files/{$fileToDelete->id}")
        ->assertUnauthorized();
});

it('filters files by search query (filename)', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    File::factory()->create(['filename' => 'test-image.jpg']);
    File::factory()->create(['filename' => 'other-file.png']);

    $response = $this->actingAs($admin)
        ->getJson('/api/files?search=test');

    $response->assertSuccessful();
    $files = $response->json('listing.items');
    expect($files)->toHaveCount(1);
    expect($files[0]['filename'])->toBe('test-image.jpg');
});

it('filters files by search query (title)', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    File::factory()->create(['title' => 'My Test Video']);
    File::factory()->create(['title' => 'Another File']);

    $response = $this->actingAs($admin)
        ->getJson('/api/files?search=test');

    $response->assertSuccessful();
    $files = $response->json('listing.items');
    expect($files)->toHaveCount(1);
    expect($files[0]['title'])->toBe('My Test Video');
});

it('filters files by search query (source)', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    File::factory()->create(['source' => 'YouTube']);
    File::factory()->create(['source' => 'local']);

    $response = $this->actingAs($admin)
        ->getJson('/api/files?search=you');

    $response->assertSuccessful();
    $files = $response->json('listing.items');
    expect($files)->toHaveCount(1);
    expect($files[0]['source'])->toBe('YouTube');
});

it('filters files by date range', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    File::factory()->create(['created_at' => '2024-01-15 10:00:00']);
    File::factory()->create(['created_at' => '2024-02-15 10:00:00']);
    File::factory()->create(['created_at' => '2024-03-15 10:00:00']);

    $response = $this->actingAs($admin)
        ->getJson('/api/files?date_from=2024-02-01&date_to=2024-02-28');

    $response->assertSuccessful();
    $files = $response->json('listing.items');
    expect($files)->toHaveCount(1);
    expect($files[0]['created_at'])->toContain('2024-02-15');
});

it('filters files by source', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    File::factory()->create(['source' => 'local']);
    File::factory()->create(['source' => 'YouTube']);
    File::factory()->create(['source' => 'NAS']);

    $response = $this->actingAs($admin)
        ->getJson('/api/files?source=local');

    $response->assertSuccessful();
    $files = $response->json('listing.items');
    expect($files)->toHaveCount(1);
    expect($files[0]['source'])->toBe('local');
});

it('filters files by mime type', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    File::factory()->create(['mime_type' => 'image/jpeg']);
    File::factory()->create(['mime_type' => 'video/mp4']);
    File::factory()->create(['mime_type' => 'audio/mpeg']);

    $response = $this->actingAs($admin)
        ->getJson('/api/files?mime_type=image');

    $response->assertSuccessful();
    $files = $response->json('listing.items');
    expect($files)->toHaveCount(1);
    expect($files[0]['mime_type'])->toBe('image/jpeg');
});

it('filters files by downloaded status', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    File::factory()->create(['downloaded' => true]);
    File::factory()->create(['downloaded' => false]);
    File::factory()->create(['downloaded' => true]);

    $response = $this->actingAs($admin)
        ->getJson('/api/files?downloaded=yes');

    $response->assertSuccessful();
    $files = $response->json('listing.items');
    expect($files)->toHaveCount(2);
    foreach ($files as $file) {
        expect($file['downloaded'])->toBe(true);
    }
});

it('filters files by not downloaded status', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    File::factory()->create(['downloaded' => true]);
    File::factory()->create(['downloaded' => false]);
    File::factory()->create(['downloaded' => false]);

    $response = $this->actingAs($admin)
        ->getJson('/api/files?downloaded=no');

    $response->assertSuccessful();
    $files = $response->json('listing.items');
    expect($files)->toHaveCount(2);
    foreach ($files as $file) {
        expect($file['downloaded'])->toBe(false);
    }
});

it('combines multiple filters', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    File::factory()->create([
        'filename' => 'test-video.mp4',
        'source' => 'YouTube',
        'mime_type' => 'video/mp4',
        'downloaded' => true,
        'created_at' => '2024-01-15 10:00:00',
    ]);
    File::factory()->create([
        'filename' => 'other-video.mp4',
        'source' => 'local',
        'mime_type' => 'video/mp4',
        'downloaded' => false,
        'created_at' => '2024-01-15 10:00:00',
    ]);
    File::factory()->create([
        'filename' => 'test-image.jpg',
        'source' => 'local',
        'mime_type' => 'image/jpeg',
        'downloaded' => true,
        'created_at' => '2024-02-15 10:00:00',
    ]);

    $response = $this->actingAs($admin)
        ->getJson('/api/files?search=test&source=YouTube&mime_type=video&downloaded=yes&date_from=2024-01-01&date_to=2024-01-31');

    $response->assertSuccessful();
    $files = $response->json('listing.items');
    expect($files)->toHaveCount(1);
    expect($files[0]['filename'])->toBe('test-video.mp4');
    expect($files[0]['source'])->toBe('YouTube');
    expect($files[0]['mime_type'])->toBe('video/mp4');
    expect($files[0]['downloaded'])->toBe(true);
});
