<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin can serve existing file', function () {
    $admin = User::factory()->admin()->create();

    $testContent = 'test file content';
    $filePath = 'private/images/ab/cd/test.jpg';
    $fullPath = storage_path("app/{$filePath}");

    // Ensure directory exists
    if (! is_dir(dirname($fullPath))) {
        mkdir(dirname($fullPath), 0755, true);
    }
    file_put_contents($fullPath, $testContent);

    $file = File::factory()->create([
        'path' => $filePath,
        'mime_type' => 'image/jpeg',
    ]);

    $response = $this->actingAs($admin)->get("/api/files/{$file->id}/serve");

    $response->assertSuccessful();
    // Verify file exists and content matches
    expect(file_exists($fullPath))->toBeTrue();
    expect(file_get_contents($fullPath))->toBe($testContent);

    // Cleanup
    if (file_exists($fullPath)) {
        unlink($fullPath);
    }
});

test('file serve returns correct Content-Type header', function () {
    $admin = User::factory()->admin()->create();

    $filePath = 'private/images/ab/cd/test.jpg';
    $fullPath = storage_path("app/{$filePath}");

    // Ensure directory exists
    if (! is_dir(dirname($fullPath))) {
        mkdir(dirname($fullPath), 0755, true);
    }
    file_put_contents($fullPath, 'test content');

    $file = File::factory()->create([
        'path' => $filePath,
        'mime_type' => 'image/jpeg',
    ]);

    $response = $this->actingAs($admin)->get("/api/files/{$file->id}/serve");

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'image/jpeg');

    // Cleanup
    if (file_exists($fullPath)) {
        unlink($fullPath);
    }
});

test('file serve returns file content', function () {
    $admin = User::factory()->admin()->create();

    $testContent = 'binary file content';
    $filePath = 'private/videos/ab/cd/test.mp4';
    $fullPath = storage_path("app/{$filePath}");

    // Ensure directory exists
    if (! is_dir(dirname($fullPath))) {
        mkdir(dirname($fullPath), 0755, true);
    }
    file_put_contents($fullPath, $testContent);

    $file = File::factory()->create([
        'path' => $filePath,
        'mime_type' => 'video/mp4',
    ]);

    $response = $this->actingAs($admin)->get("/api/files/{$file->id}/serve");

    $response->assertSuccessful();
    // Verify file exists and content matches
    expect(file_exists($fullPath))->toBeTrue();
    expect(file_get_contents($fullPath))->toBe($testContent);

    // Cleanup
    if (file_exists($fullPath)) {
        unlink($fullPath);
    }
});

test('serving file with missing path returns 404', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create([
        'path' => null,
    ]);

    $response = $this->actingAs($admin)->get("/api/files/{$file->id}/serve");

    $response->assertNotFound();
});

test('serving file that does not exist on disk returns 404', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create([
        'path' => 'private/images/ab/cd/nonexistent.jpg',
    ]);

    $response = $this->actingAs($admin)->get("/api/files/{$file->id}/serve");

    $response->assertNotFound();
});

test('regular user can serve files', function () {
    $user = User::factory()->create();
    $filePath = 'private/images/ab/cd/test.jpg';
    $fullPath = storage_path("app/{$filePath}");

    // Ensure directory exists
    if (! is_dir(dirname($fullPath))) {
        mkdir(dirname($fullPath), 0755, true);
    }
    file_put_contents($fullPath, 'test content');
    $file = File::factory()->create(['path' => $filePath]);

    $response = $this->actingAs($user)->get("/api/files/{$file->id}/serve");

    $response->assertSuccessful();

    // Cleanup
    if (file_exists($fullPath)) {
        unlink($fullPath);
    }
});

test('guest cannot serve files', function () {
    $file = File::factory()->create();

    $response = $this->get("/api/files/{$file->id}/serve");

    $response->assertRedirect('/login');
});
