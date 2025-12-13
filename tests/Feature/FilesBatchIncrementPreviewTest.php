<?php

use App\Models\BrowseTab;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('batch increments preview count for multiple files', function () {
    $admin = User::factory()->admin()->create();
    $file1 = File::factory()->create(['previewed_count' => 0]);
    $file2 = File::factory()->create(['previewed_count' => 1]);
    $file3 = File::factory()->create(['previewed_count' => 2]);

    $response = $this->actingAs($admin)->postJson('/api/files/preview/batch', [
        'file_ids' => [$file1->id, $file2->id, $file3->id],
    ]);

    $response->assertSuccessful();
    $response->assertJsonStructure([
        'message',
        'results' => [
            '*' => ['id', 'previewed_count', 'auto_disliked'],
        ],
    ]);

    $file1->refresh();
    $file2->refresh();
    $file3->refresh();

    expect($file1->previewed_count)->toBe(1);
    expect($file2->previewed_count)->toBe(2);
    expect($file3->previewed_count)->toBe(3);
});

test('batch auto-dislikes multiple files when previewed_count reaches 3', function () {
    $admin = User::factory()->admin()->create();
    $file1 = File::factory()->create([
        'previewed_count' => 2,
        'source' => 'wallhaven',
        'path' => null,
        'blacklisted_at' => null,
    ]);
    $file2 = File::factory()->create([
        'previewed_count' => 2,
        'source' => 'wallhaven',
        'path' => null,
        'blacklisted_at' => null,
    ]);
    $tab = BrowseTab::factory()->for($admin)->create();
    $tab->files()->attach([$file1->id, $file2->id], ['position' => 0]);

    $response = $this->actingAs($admin)->postJson('/api/files/preview/batch', [
        'file_ids' => [$file1->id, $file2->id],
    ]);

    $response->assertSuccessful();

    $file1->refresh();
    $file2->refresh();

    expect($file1->auto_disliked)->toBeTrue();
    expect($file2->auto_disliked)->toBeTrue();
    expect($file1->previewed_count)->toBe(3);
    expect($file2->previewed_count)->toBe(3);

    // Verify dislike reactions were created
    $reaction1 = Reaction::where('file_id', $file1->id)
        ->where('user_id', $admin->id)
        ->where('type', 'dislike')
        ->first();
    $reaction2 = Reaction::where('file_id', $file2->id)
        ->where('user_id', $admin->id)
        ->where('type', 'dislike')
        ->first();

    expect($reaction1)->not->toBeNull();
    expect($reaction2)->not->toBeNull();

    // Verify files were detached from tab
    expect($tab->files()->where('files.id', $file1->id)->exists())->toBeFalse();
    expect($tab->files()->where('files.id', $file2->id)->exists())->toBeFalse();
});

test('batch does not auto-dislike files that already have reactions', function () {
    $admin = User::factory()->admin()->create();
    $file1 = File::factory()->create([
        'previewed_count' => 2,
        'source' => 'wallhaven',
        'path' => null,
        'blacklisted_at' => null,
    ]);
    $file2 = File::factory()->create([
        'previewed_count' => 2,
        'source' => 'wallhaven',
        'path' => null,
        'blacklisted_at' => null,
    ]);

    // File1 already has a reaction
    Reaction::create([
        'file_id' => $file1->id,
        'user_id' => $admin->id,
        'type' => 'like',
    ]);

    $response = $this->actingAs($admin)->postJson('/api/files/preview/batch', [
        'file_ids' => [$file1->id, $file2->id],
    ]);

    $response->assertSuccessful();

    $file1->refresh();
    $file2->refresh();

    // File1 should not be auto-disliked (has reaction)
    expect($file1->auto_disliked)->toBeFalse();
    // File2 should be auto-disliked (no reaction)
    expect($file2->auto_disliked)->toBeTrue();
});

test('batch handles mixed candidates correctly', function () {
    $admin = User::factory()->admin()->create();
    $file1 = File::factory()->create([
        'previewed_count' => 2,
        'source' => 'wallhaven',
        'path' => null,
        'blacklisted_at' => null,
    ]);
    $file2 = File::factory()->create([
        'previewed_count' => 1, // Not a candidate (previewed_count < 3)
        'source' => 'wallhaven',
        'path' => null,
        'blacklisted_at' => null,
    ]);
    $file3 = File::factory()->create([
        'previewed_count' => 2,
        'source' => 'local', // Not a candidate (source is local)
        'path' => null,
        'blacklisted_at' => null,
    ]);

    $response = $this->actingAs($admin)->postJson('/api/files/preview/batch', [
        'file_ids' => [$file1->id, $file2->id, $file3->id],
    ]);

    $response->assertSuccessful();

    $file1->refresh();
    $file2->refresh();
    $file3->refresh();

    // Only file1 should be auto-disliked (meets all criteria)
    expect($file1->auto_disliked)->toBeTrue();
    expect($file2->auto_disliked)->toBeFalse();
    expect($file3->auto_disliked)->toBeFalse();
});

test('batch detaches files from multiple tabs', function () {
    $admin = User::factory()->admin()->create();
    $file1 = File::factory()->create([
        'previewed_count' => 2,
        'source' => 'wallhaven',
        'path' => null,
        'blacklisted_at' => null,
    ]);
    $file2 = File::factory()->create([
        'previewed_count' => 2,
        'source' => 'wallhaven',
        'path' => null,
        'blacklisted_at' => null,
    ]);

    $tab1 = BrowseTab::factory()->for($admin)->create();
    $tab2 = BrowseTab::factory()->for($admin)->create();
    $tab1->files()->attach([$file1->id, $file2->id], ['position' => 0]);
    $tab2->files()->attach([$file1->id, $file2->id], ['position' => 0]);

    $response = $this->actingAs($admin)->postJson('/api/files/preview/batch', [
        'file_ids' => [$file1->id, $file2->id],
    ]);

    $response->assertSuccessful();

    // Verify files were detached from both tabs
    expect($tab1->files()->where('files.id', $file1->id)->exists())->toBeFalse();
    expect($tab1->files()->where('files.id', $file2->id)->exists())->toBeFalse();
    expect($tab2->files()->where('files.id', $file1->id)->exists())->toBeFalse();
    expect($tab2->files()->where('files.id', $file2->id)->exists())->toBeFalse();
});

test('batch validates file_ids array', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson('/api/files/preview/batch', [
        'file_ids' => 'not-an-array',
    ]);

    $response->assertUnprocessable();
});

test('batch validates file_ids exist', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson('/api/files/preview/batch', [
        'file_ids' => [99999, 99998],
    ]);

    $response->assertUnprocessable();
});
