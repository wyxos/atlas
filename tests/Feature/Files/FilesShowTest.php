<?php

use App\Models\File;
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

test('regular user cannot view file details', function () {
    $user = User::factory()->create();
    $file = File::factory()->create();

    $response = $this->actingAs($user)->getJson("/api/files/{$file->id}");

    $response->assertForbidden();
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

