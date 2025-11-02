<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Services\BlacklistService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

it('blacklists files and upserts dislike for current user', function () {
    Storage::fake('atlas_app');
    Storage::fake('atlas');
    Storage::fake('atlas_bin');

    // Create files; one with a local path we can delete
    Storage::disk('atlas_app')->put('media/a.jpg', 'data-a');

    $fileA = File::factory()->create([
        'source' => 'reddit',
        'filename' => 'a.jpg',
        'path' => 'media/a.jpg',
        'blacklisted_at' => null,
        'blacklist_reason' => null,
    ]);
    $fileB = File::factory()->create([
        'source' => 'twitter',
        'filename' => 'b.jpg',
        'path' => null,
        'blacklisted_at' => null,
        'blacklist_reason' => null,
    ]);

    $user = User::factory()->create();
    $other = User::factory()->create();

    // Pre-existing reaction by another user should remain untouched
    Reaction::factory()->create([
        'file_id' => $fileB->id,
        'user_id' => $other->id,
        'type' => 'like',
    ]);

    $this->actingAs($user);

    $svc = new BlacklistService;
    $result = $svc->apply([$fileA->id, $fileB->id], 'test:service');

    expect($result['newly_blacklisted_count'] ?? null)->toBeInt();
    // Both files should now be blacklisted
    foreach ([$fileA->id, $fileB->id] as $id) {
        $row = DB::table('files')->where('id', $id)->first();
        expect($row)->not->toBeNull();
        expect($row->blacklisted_at)->not->toBeNull();
        expect($row->blacklist_reason)->toBe('test:service');
    }

    // Non-local file should be deleted from disk
    Storage::disk('atlas_app')->assertMissing('media/a.jpg');

    // Current user has dislike reactions for both
    $userLikes = Reaction::query()->where('user_id', $user->id)->whereIn('file_id', [$fileA->id, $fileB->id])->pluck('type', 'file_id')->toArray();
    expect($userLikes[$fileA->id] ?? null)->toBe('dislike');
    expect($userLikes[$fileB->id] ?? null)->toBe('dislike');

    // Other user's prior reaction preserved
    expect(Reaction::query()->where('user_id', $other->id)->where('file_id', $fileB->id)->where('type', 'like')->exists())->toBeTrue();

    // Idempotent: calling again does not duplicate and keeps dislike
    $svc->apply([$fileA->id, $fileB->id], 'test:service');
    $count = Reaction::query()->where('user_id', $user->id)->whereIn('file_id', [$fileA->id, $fileB->id])->count();
    expect($count)->toBe(2);
});

it('moves local file to atlas_bin when blacklisting', function () {
    Storage::fake('atlas_app');
    Storage::fake('atlas');
    Storage::fake('atlas_bin');

    Storage::disk('atlas_app')->put('photos/2025/11/local-file.jpg', 'local-content');

    $fileLocal = File::factory()->create([
        'source' => 'local',
        'filename' => 'local-file.jpg',
        'path' => 'photos/2025/11/local-file.jpg',
        'blacklisted_at' => null,
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $svc = new BlacklistService;
    $svc->apply([$fileLocal->id], 'test:local-move');

    // File should be moved to atlas_bin
    Storage::disk('atlas_bin')->assertExists('photos/2025/11/local-file.jpg');
    expect(Storage::disk('atlas_bin')->get('photos/2025/11/local-file.jpg'))->toBe('local-content');

    // File should be deleted from atlas_app
    Storage::disk('atlas_app')->assertMissing('photos/2025/11/local-file.jpg');

    // Should be blacklisted in DB
    $row = DB::table('files')->where('id', $fileLocal->id)->first();
    expect($row->blacklisted_at)->not->toBeNull();
});

it('deletes non-local file from both disks when blacklisting', function () {
    Storage::fake('atlas_app');
    Storage::fake('atlas');
    Storage::fake('atlas_bin');

    Storage::disk('atlas_app')->put('photos/remote-file.jpg', 'remote-content-app');
    Storage::disk('atlas')->put('photos/remote-file.jpg', 'remote-content-atlas');

    $fileRemote = File::factory()->create([
        'source' => 'reddit',
        'filename' => 'remote-file.jpg',
        'path' => 'photos/remote-file.jpg',
        'blacklisted_at' => null,
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $svc = new BlacklistService;
    $svc->apply([$fileRemote->id], 'test:remote-delete');

    // File should be deleted from both atlas_app and atlas
    Storage::disk('atlas_app')->assertMissing('photos/remote-file.jpg');
    Storage::disk('atlas')->assertMissing('photos/remote-file.jpg');

    // File should NOT be in atlas_bin
    Storage::disk('atlas_bin')->assertMissing('photos/remote-file.jpg');

    // Should be blacklisted in DB
    $row = DB::table('files')->where('id', $fileRemote->id)->first();
    expect($row->blacklisted_at)->not->toBeNull();
});

it('moves local file from atlas disk to atlas_bin when blacklisting', function () {
    Storage::fake('atlas_app');
    Storage::fake('atlas');
    Storage::fake('atlas_bin');

    // File only exists on atlas disk
    Storage::disk('atlas')->put('photos/local-on-atlas.jpg', 'atlas-local-content');

    $fileLocal = File::factory()->create([
        'source' => 'local',
        'filename' => 'local-on-atlas.jpg',
        'path' => 'photos/local-on-atlas.jpg',
        'blacklisted_at' => null,
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $svc = new BlacklistService;
    $svc->apply([$fileLocal->id], 'test:local-atlas-move');

    // File should be moved to atlas_bin
    Storage::disk('atlas_bin')->assertExists('photos/local-on-atlas.jpg');
    expect(Storage::disk('atlas_bin')->get('photos/local-on-atlas.jpg'))->toBe('atlas-local-content');

    // File should be deleted from atlas
    Storage::disk('atlas')->assertMissing('photos/local-on-atlas.jpg');

    // Should be blacklisted in DB
    $row = DB::table('files')->where('id', $fileLocal->id)->first();
    expect($row->blacklisted_at)->not->toBeNull();
});
