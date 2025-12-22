<?php

use App\Models\Tab;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('atlas-app');
    Storage::fake('local');
});

test('admin can delete all files in database', function () {
    $admin = User::factory()->admin()->create();
    $file1 = File::factory()->create();
    $file2 = File::factory()->create();
    $file3 = File::factory()->create();

    // Create browse tabs for the admin with files attached
    $tab1 = Tab::factory()->for($admin)->create();
    $tab1->files()->attach([$file1->id => ['position' => 0], $file2->id => ['position' => 1]]);

    $tab2 = Tab::factory()->for($admin)->create();
    $tab2->files()->attach([$file3->id => ['position' => 0]]);

    $response = $this->actingAs($admin)->deleteJson('/api/files');

    $response->assertSuccessful();
    $response->assertJson([
        'message' => 'All files deleted successfully.',
    ]);
    $response->assertJsonStructure([
        'message',
        'deleted_count',
    ]);

    // Verify all files are deleted
    $this->assertDatabaseMissing('files', ['id' => $file1->id]);
    $this->assertDatabaseMissing('files', ['id' => $file2->id]);
    $this->assertDatabaseMissing('files', ['id' => $file3->id]);
});

test('deleteAll deletes all files in database regardless of user', function () {
    $admin = User::factory()->admin()->create();
    $otherUser = User::factory()->create();

    $file1 = File::factory()->create();
    $file2 = File::factory()->create();
    $file3 = File::factory()->create();

    // Create browse tabs for admin
    $adminTab = Tab::factory()->for($admin)->create();
    $adminTab->files()->attach([$file1->id => ['position' => 0], $file2->id => ['position' => 1]]);

    // Create browse tabs for other user
    $otherTab = Tab::factory()->for($otherUser)->create();
    $otherTab->files()->attach([$file3->id => ['position' => 0]]);

    $response = $this->actingAs($admin)->deleteJson('/api/files');

    $response->assertSuccessful();

    // Verify all files are deleted, including other user's files
    $this->assertDatabaseMissing('files', ['id' => $file1->id]);
    $this->assertDatabaseMissing('files', ['id' => $file2->id]);
    $this->assertDatabaseMissing('files', ['id' => $file3->id]);
});

test('deleteAll returns correct deleted count', function () {
    $admin = User::factory()->admin()->create();
    $file1 = File::factory()->create();
    $file2 = File::factory()->create();
    $file3 = File::factory()->create(); // File not attached to any tab

    $tab = Tab::factory()->for($admin)->create();
    $tab->files()->attach([$file1->id => ['position' => 0], $file2->id => ['position' => 1]]);

    $response = $this->actingAs($admin)->deleteJson('/api/files');

    $response->assertSuccessful();
    $response->assertJson([
        'deleted_count' => 3, // All files in database, including unattached ones
    ]);
});

test('regular user cannot delete all files', function () {
    $user = User::factory()->create();
    $file = File::factory()->create();

    $tab = Tab::factory()->for($user)->create();
    $tab->files()->attach([$file->id => ['position' => 0]]);

    $response = $this->actingAs($user)->deleteJson('/api/files');

    $response->assertForbidden();
    $this->assertDatabaseHas('files', ['id' => $file->id]);
});

test('guest cannot delete all files', function () {
    $file = File::factory()->create();

    $response = $this->deleteJson('/api/files');

    $response->assertUnauthorized();
    $this->assertDatabaseHas('files', ['id' => $file->id]);
});

test('deleteAll handles user with no files gracefully', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->deleteJson('/api/files');

    $response->assertSuccessful();
    $response->assertJson([
        'message' => 'All files deleted successfully.',
        'deleted_count' => 0,
    ]);
});

test('deleteAll empties atlas app storage', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create();

    // Create files in atlas app storage
    $atlasDisk = Storage::disk('atlas-app');
    $atlasDisk->put('test-file.txt', 'test content');
    $atlasDisk->put('subdir/another-file.txt', 'more content');

    // Create files in private storage
    $localDisk = Storage::disk('local');
    $localDisk->put('private/test-private.txt', 'private content');
    $localDisk->put('private/images/test-image.jpg', 'image content');

    $response = $this->actingAs($admin)->deleteJson('/api/files');

    $response->assertSuccessful();

    // Verify atlas app storage is emptied
    $atlasDisk->assertMissing('test-file.txt');
    $atlasDisk->assertMissing('subdir/another-file.txt');

    // Verify private storage is emptied
    $localDisk->assertMissing('private/test-private.txt');
    $localDisk->assertMissing('private/images/test-image.jpg');
    expect($localDisk->exists('private'))->toBeFalse();
});
