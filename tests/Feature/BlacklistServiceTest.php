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

    // Create files; one with a local path we can delete
    Storage::disk('atlas_app')->put('media/a.jpg', 'data-a');

    $fileA = File::factory()->create([
        'filename' => 'a.jpg',
        'path' => 'media/a.jpg',
        'blacklisted_at' => null,
        'blacklist_reason' => null,
    ]);
    $fileB = File::factory()->create([
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

    // Local file should be deleted from disk
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
