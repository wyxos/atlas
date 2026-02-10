<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('local browse can filter to reacted files', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create([
        'params' => ['feed' => 'local'],
    ]);

    $reacted = File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subDay(),
        'source' => 'CivitAI',
    ]);
    $notReacted = File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subHours(12),
        'source' => 'Wallhaven',
    ]);

    Reaction::create([
        'file_id' => $reacted->id,
        'user_id' => $user->id,
        'type' => 'love',
    ]);

    $response = $this->actingAs($user)->getJson("/api/browse?tab_id={$tab->id}&feed=local&source=all&limit=50&reaction_mode=reacted");
    $response->assertSuccessful();

    $ids = collect($response->json('items'))->pluck('id')->all();
    expect($ids)->toContain($reacted->id);
    expect($ids)->not->toContain($notReacted->id);
});

test('local browse can sort by reaction timestamp', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create([
        'params' => ['feed' => 'local'],
    ]);

    $older = File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subDays(2),
        'source' => 'CivitAI',
    ]);
    $newer = File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subDay(),
        'source' => 'CivitAI',
    ]);

    $olderReaction = Reaction::create([
        'file_id' => $older->id,
        'user_id' => $user->id,
        'type' => 'dislike',
    ]);
    Reaction::whereKey($olderReaction->id)->update([
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);

    $newerReaction = Reaction::create([
        'file_id' => $newer->id,
        'user_id' => $user->id,
        'type' => 'dislike',
    ]);
    Reaction::whereKey($newerReaction->id)->update([
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);

    $response = $this->actingAs($user)->getJson("/api/browse?tab_id={$tab->id}&feed=local&source=all&limit=50&reaction_mode=types&reaction[]=dislike&sort=reaction_at");
    $response->assertSuccessful();

    $ids = collect($response->json('items'))->pluck('id')->values()->all();
    expect($ids[0] ?? null)->toBe($newer->id);
    expect($ids)->toContain($older->id);
});

test('local browse can filter blacklisted files by manual or auto type', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create([
        'params' => ['feed' => 'local'],
    ]);

    $manual = File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subDay(),
        'blacklisted_at' => now(),
        'blacklist_reason' => 'manual reason',
        'source' => 'CivitAI',
    ]);
    $auto = File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subHours(12),
        'blacklisted_at' => now(),
        'blacklist_reason' => null,
        'source' => 'CivitAI',
    ]);

    $manualResp = $this->actingAs($user)->getJson("/api/browse?tab_id={$tab->id}&feed=local&source=all&limit=50&blacklisted=yes&blacklist_type=manual");
    $manualResp->assertSuccessful();
    $manualIds = collect($manualResp->json('items'))->pluck('id')->all();
    expect($manualIds)->toContain($manual->id);
    expect($manualIds)->not->toContain($auto->id);

    $autoResp = $this->actingAs($user)->getJson("/api/browse?tab_id={$tab->id}&feed=local&source=all&limit=50&blacklisted=yes&blacklist_type=auto");
    $autoResp->assertSuccessful();
    $autoIds = collect($autoResp->json('items'))->pluck('id')->all();
    expect($autoIds)->toContain($auto->id);
    expect($autoIds)->not->toContain($manual->id);
});

test('local browse can cap previewed_count', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create([
        'params' => ['feed' => 'local'],
    ]);

    $ok = File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subDay(),
        'previewed_count' => 1,
        'source' => 'CivitAI',
    ]);
    $tooMany = File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subHours(12),
        'previewed_count' => 3,
        'source' => 'CivitAI',
    ]);

    $response = $this->actingAs($user)->getJson("/api/browse?tab_id={$tab->id}&feed=local&source=all&limit=50&max_previewed_count=2");
    $response->assertSuccessful();

    $ids = collect($response->json('items'))->pluck('id')->all();
    expect($ids)->toContain($ok->id);
    expect($ids)->not->toContain($tooMany->id);
});

test('local reacted mode excludes dislikes', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create([
        'params' => ['feed' => 'local'],
    ]);

    $liked = File::factory()->create([
        'source' => 'CivitAI',
    ]);
    $disliked = File::factory()->create([
        'source' => 'CivitAI',
    ]);

    Reaction::create([
        'file_id' => $liked->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);
    Reaction::create([
        'file_id' => $disliked->id,
        'user_id' => $user->id,
        'type' => 'dislike',
    ]);

    $response = $this->actingAs($user)->getJson("/api/browse?tab_id={$tab->id}&feed=local&source=all&limit=50&reaction_mode=reacted");
    $response->assertSuccessful();

    $ids = collect($response->json('items'))->pluck('id')->all();
    expect($ids)->toContain($liked->id);
    expect($ids)->not->toContain($disliked->id);
});

test('local browse can filter to unreacted files', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create([
        'params' => ['feed' => 'local'],
    ]);

    $reacted = File::factory()->create([
        'source' => 'CivitAI',
    ]);
    $unreacted = File::factory()->create([
        'source' => 'CivitAI',
    ]);

    Reaction::create([
        'file_id' => $reacted->id,
        'user_id' => $user->id,
        'type' => 'funny',
    ]);

    $response = $this->actingAs($user)->getJson("/api/browse?tab_id={$tab->id}&feed=local&source=all&limit=50&reaction_mode=unreacted");
    $response->assertSuccessful();

    $ids = collect($response->json('items'))->pluck('id')->all();
    expect($ids)->toContain($unreacted->id);
    expect($ids)->not->toContain($reacted->id);
});

test('max_previewed_count=0 returns only unpreviewed files', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create([
        'params' => ['feed' => 'local'],
    ]);

    $zero = File::factory()->create([
        'source' => 'CivitAI',
        'previewed_count' => 0,
    ]);
    $one = File::factory()->create([
        'source' => 'CivitAI',
        'previewed_count' => 1,
    ]);

    $response = $this->actingAs($user)->getJson("/api/browse?tab_id={$tab->id}&feed=local&source=all&limit=50&max_previewed_count=0");
    $response->assertSuccessful();

    $ids = collect($response->json('items'))->pluck('id')->all();
    expect($ids)->toContain($zero->id);
    expect($ids)->not->toContain($one->id);
});

test('local browse can filter by file type (audio only)', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create([
        'params' => ['feed' => 'local'],
    ]);

    $audio = File::factory()->create([
        'source' => 'CivitAI',
        'mime_type' => 'audio/mpeg',
    ]);
    $image = File::factory()->create([
        'source' => 'CivitAI',
        'mime_type' => 'image/jpeg',
    ]);

    $response = $this->actingAs($user)->getJson("/api/browse?tab_id={$tab->id}&feed=local&source=all&limit=50&file_type[]=audio");
    $response->assertSuccessful();

    $ids = collect($response->json('items'))->pluck('id')->all();
    expect($ids)->toContain($audio->id);
    expect($ids)->not->toContain($image->id);
});
