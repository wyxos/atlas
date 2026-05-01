<?php

use App\Models\Container;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin can view file details', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create();

    $response = $this->actingAs($admin)->getJson("/api/files/{$file->id}");

    $response->assertSuccessful();
    $response->assertJsonStructure([
        'file' => [
            'id',
        ],
    ]);
});

test('admin receives FileResource with correct structure', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create();

    $response = $this->actingAs($admin)->getJson("/api/files/{$file->id}");

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['file'])->toBeArray();
    expect($data['file']['id'])->toBe($file->id);
});

test('regular user can view file details', function () {
    $user = User::factory()->create();
    $file = File::factory()->create();

    $response = $this->actingAs($user)->getJson("/api/files/{$file->id}");

    $response->assertSuccessful();
});

test('guest cannot view file details', function () {
    $file = File::factory()->create();

    $response = $this->getJson("/api/files/{$file->id}");

    $response->assertUnauthorized();
});

test('viewing non-existent file returns 404', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->getJson('/api/files/99999');

    $response->assertNotFound();
});

test('file show includes container state and stats for the current user', function () {
    $user = User::factory()->create();
    $file = File::factory()->create();
    $container = Container::factory()->create([
        'type' => 'gallery',
        'source' => 'CivitAI',
        'source_id' => 'gallery-123',
        'referrer' => 'https://example.com/gallery/123',
        'action_type' => 'blacklist',
        'blacklisted_at' => now(),
    ]);

    $unreactedFile = File::factory()->create();
    $blacklistedFile = File::factory()->create([
        'blacklisted_at' => now(),
    ]);
    $notFoundFile = File::factory()->create([
        'not_found' => true,
    ]);
    $funnyFile = File::factory()->create();
    $positiveFile = File::factory()->create();

    $container->files()->attach([
        $file->id,
        $unreactedFile->id,
        $blacklistedFile->id,
        $notFoundFile->id,
        $funnyFile->id,
        $positiveFile->id,
    ]);

    Reaction::create([
        'file_id' => $funnyFile->id,
        'user_id' => $user->id,
        'type' => 'funny',
    ]);

    Reaction::create([
        'file_id' => $positiveFile->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    $response = $this->actingAs($user)->getJson("/api/files/{$file->id}");

    $response->assertSuccessful();
    $response->assertJsonPath('file.containers.0.id', $container->id);
    $response->assertJsonPath('file.containers.0.type', 'gallery');
    $response->assertJsonPath('file.containers.0.source', 'CivitAI');
    $response->assertJsonPath('file.containers.0.source_id', 'gallery-123');
    $response->assertJsonPath('file.containers.0.referrer', 'https://example.com/gallery/123');
    $response->assertJsonPath('file.containers.0.blacklisted', true);
    $response->assertJsonPath('file.containers.0.action_type', 'blacklist');
    $response->assertJsonPath('file.containers.0.file_stats.unreacted', 2);
    $response->assertJsonPath('file.containers.0.file_stats.blacklisted', 1);
    $response->assertJsonPath('file.containers.0.file_stats.positive', 2);
});
