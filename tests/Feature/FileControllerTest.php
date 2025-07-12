<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

test('files index page can be rendered for admin', function () {
    $user = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($user)->get('/files');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Files/Index'));
});

test('files index page cannot be accessed by non-admin', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $response = $this->actingAs($user)->get('/files');

    $response->assertStatus(403);
});

test('files index page shows search results', function () {
    $user = User::factory()->create(['is_admin' => true]);

    // Create test files
    File::factory()->create([
        'filename' => 'test-audio.mp3',
        'path' => '/music/test-audio.mp3',
        'ext' => 'mp3',
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
    ]);

    File::factory()->create([
        'filename' => 'other-file.txt',
        'path' => '/documents/other-file.txt',
        'ext' => 'txt',
        'mime_type' => 'text/plain',
        'not_found' => false,
    ]);

    $response = $this->actingAs($user)->get('/files?query=test');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) =>
        $page->component('Files/Index')
             ->has('files.data', 1)
             ->where('search', 'test')
    );
});

test('files index page filters not found files', function () {
    $user = User::factory()->create(['is_admin' => true]);

    // Create test files
    File::factory()->create([
        'filename' => 'found-file.mp3',
        'path' => '/music/found-file.mp3',
        'ext' => 'mp3',
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
    ]);

    File::factory()->create([
        'filename' => 'missing-file.mp3',
        'path' => '/music/missing-file.mp3',
        'ext' => 'mp3',
        'mime_type' => 'audio/mpeg',
        'not_found' => true,
    ]);

    $response = $this->actingAs($user)->get('/files?not_found=true');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) =>
        $page->component('Files/Index')
             ->has('files.data', 1)
             ->where('notFoundFilter', true)
    );
});

test('file can be deleted by admin', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $fileToDelete = File::factory()->create([
        'filename' => 'test-file.mp3',
        'path' => '/music/test-file.mp3',
        'ext' => 'mp3',
        'mime_type' => 'audio/mpeg',
    ]);

    $response = $this->actingAs($admin)->delete("/files/{$fileToDelete->id}");

    $response->assertRedirect('/files');
    $response->assertSessionHas('success');

    $this->assertDatabaseMissing('files', [
        'id' => $fileToDelete->id,
    ]);
});

test('file cannot be deleted by non-admin', function () {
    $regularUser = User::factory()->create(['is_admin' => false]);
    $fileToDelete = File::factory()->create([
        'filename' => 'test-file.mp3',
        'path' => '/music/test-file.mp3',
        'ext' => 'mp3',
        'mime_type' => 'audio/mpeg',
    ]);

    $response = $this->actingAs($regularUser)->delete("/files/{$fileToDelete->id}");

    $response->assertStatus(403);

    $this->assertDatabaseHas('files', [
        'id' => $fileToDelete->id,
    ]);
});

test('files index page transforms file data correctly', function () {
    $user = User::factory()->create(['is_admin' => true]);

    $file = File::factory()->create([
        'filename' => 'test-audio.mp3',
        'path' => '/music/test-audio.mp3',
        'ext' => 'mp3',
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
    ]);

    $response = $this->actingAs($user)->get('/files');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) =>
        $page->component('Files/Index')
             ->has('files.data', 1)
             ->where('files.data.0.name', 'test-audio.mp3')
             ->where('files.data.0.type', 'mp3')
             ->where('files.data.0.path', '/music/test-audio.mp3')
    );
});
