<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin can delete file', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create();

    $response = $this->actingAs($admin)->deleteJson("/api/files/{$file->id}");

    $response->assertSuccessful();
    $response->assertJson([
        'message' => 'File deleted successfully.',
    ]);
});

test('deleting file returns success message', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create();

    $response = $this->actingAs($admin)->deleteJson("/api/files/{$file->id}");

    $response->assertJson([
        'message' => 'File deleted successfully.',
    ]);
});

test('regular user cannot delete files', function () {
    $user = User::factory()->create();
    $file = File::factory()->create();

    $response = $this->actingAs($user)->deleteJson("/api/files/{$file->id}");

    $response->assertForbidden();
    $this->assertDatabaseHas('files', ['id' => $file->id]);
});

test('guest cannot delete files', function () {
    $file = File::factory()->create();

    $response = $this->deleteJson("/api/files/{$file->id}");

    $response->assertUnauthorized();
    $this->assertDatabaseHas('files', ['id' => $file->id]);
});

test('deleting non-existent file returns 404', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->deleteJson('/api/files/99999');

    $response->assertNotFound();
});

test('deleted file is removed from database', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create();
    $fileId = $file->id;

    $this->actingAs($admin)->deleteJson("/api/files/{$fileId}");

    $this->assertDatabaseMissing('files', ['id' => $fileId]);
});

