<?php

use App\Models\BrowseTab;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin can delete all files from their browse tabs', function () {
    $admin = User::factory()->admin()->create();
    $file1 = File::factory()->create();
    $file2 = File::factory()->create();
    $file3 = File::factory()->create();

    // Create browse tabs for the admin with files attached
    $tab1 = BrowseTab::factory()->for($admin)->create();
    $tab1->files()->attach([$file1->id => ['position' => 0], $file2->id => ['position' => 1]]);

    $tab2 = BrowseTab::factory()->for($admin)->create();
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

test('deleteAll only deletes files from user\'s browse tabs', function () {
    $admin = User::factory()->admin()->create();
    $otherUser = User::factory()->create();

    $file1 = File::factory()->create();
    $file2 = File::factory()->create();
    $file3 = File::factory()->create();

    // Create browse tabs for admin
    $adminTab = BrowseTab::factory()->for($admin)->create();
    $adminTab->files()->attach([$file1->id => ['position' => 0], $file2->id => ['position' => 1]]);

    // Create browse tabs for other user
    $otherTab = BrowseTab::factory()->for($otherUser)->create();
    $otherTab->files()->attach([$file3->id => ['position' => 0]]);

    $response = $this->actingAs($admin)->deleteJson('/api/files');

    $response->assertSuccessful();

    // Verify admin's files are deleted
    $this->assertDatabaseMissing('files', ['id' => $file1->id]);
    $this->assertDatabaseMissing('files', ['id' => $file2->id]);

    // Verify other user's files are NOT deleted
    $this->assertDatabaseHas('files', ['id' => $file3->id]);
});

test('deleteAll returns correct deleted count', function () {
    $admin = User::factory()->admin()->create();
    $file1 = File::factory()->create();
    $file2 = File::factory()->create();

    $tab = BrowseTab::factory()->for($admin)->create();
    $tab->files()->attach([$file1->id => ['position' => 0], $file2->id => ['position' => 1]]);

    $response = $this->actingAs($admin)->deleteJson('/api/files');

    $response->assertSuccessful();
    $response->assertJson([
        'deleted_count' => 2,
    ]);
});

test('regular user cannot delete all files', function () {
    $user = User::factory()->create();
    $file = File::factory()->create();

    $tab = BrowseTab::factory()->for($user)->create();
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
