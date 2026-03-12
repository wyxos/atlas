<?php

use App\Models\Container;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Services\MetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('dashboard metrics report file and reaction totals', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $this->actingAs($user);

    $manualBlacklisted = File::factory()->create([
        'blacklisted_at' => now(),
        'blacklist_reason' => 'Manual review',
        'downloaded' => true,
        'source' => 'local',
    ]);

    $autoBlacklisted = File::factory()->create([
        'blacklisted_at' => now(),
        'blacklist_reason' => null,
        'downloaded' => false,
        'source' => 'NAS',
    ]);

    $notFound = File::factory()->create([
        'not_found' => true,
        'downloaded' => false,
        'source' => 'local',
    ]);

    $unblacklisted = File::factory()->create([
        'downloaded' => true,
        'source' => 'Booru',
    ]);
    $unreacted = File::factory()->create([
        'downloaded' => false,
        'source' => 'YouTube',
    ]);

    $containerUser = Container::factory()->create([
        'type' => 'User',
        'source' => 'CivitAI',
        'source_id' => 'user-1',
    ]);
    $containerGallery = Container::factory()->create([
        'type' => 'Gallery',
        'source' => 'Booru',
        'source_id' => 'gallery-1',
        'blacklisted_at' => now(),
    ]);
    $containerPost = Container::factory()->create([
        'type' => 'Post',
        'source' => 'CivitAI',
        'source_id' => 'post-1',
    ]);

    $containerUser->files()->attach([$manualBlacklisted->id, $unblacklisted->id]);
    $containerGallery->files()->attach([$autoBlacklisted->id, $notFound->id]);
    $containerPost->files()->attach([$unreacted->id]);

    Reaction::create([
        'file_id' => $manualBlacklisted->id,
        'user_id' => $user->id,
        'type' => 'love',
    ]);

    Reaction::create([
        'file_id' => $manualBlacklisted->id,
        'user_id' => $otherUser->id,
        'type' => 'love',
    ]);

    Reaction::create([
        'file_id' => $autoBlacklisted->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    Reaction::create([
        'file_id' => $unblacklisted->id,
        'user_id' => $user->id,
        'type' => 'funny',
    ]);

    Reaction::create([
        'file_id' => $unblacklisted->id,
        'user_id' => $otherUser->id,
        'type' => 'dislike',
    ]);

    app(MetricsService::class)->syncAll();

    $response = $this->getJson('/api/dashboard/metrics');

    $response->assertSuccessful();
    $response->assertJson([
        'files' => [
            'total' => 5,
            'reactions' => [
                'love' => 1,
                'like' => 1,
                'dislike' => 1,
                'funny' => 1,
            ],
            'downloaded' => 2,
            'local' => 2,
            'non_local' => 3,
            'blacklisted' => [
                'total' => 2,
                'manual' => 1,
                'auto' => 1,
            ],
            'not_found' => 1,
            'unreacted_not_blacklisted' => 2,
        ],
        'containers' => [
            'total' => 2,
            'blacklisted' => 1,
        ],
    ]);
});

test('incrementContainersByCounts applies batched deltas and clamps at zero', function () {
    $first = Container::factory()->create([
        'files_downloaded' => 2,
    ]);

    $second = Container::factory()->create([
        'files_downloaded' => 0,
    ]);

    app(MetricsService::class)->incrementContainersByCounts('files_downloaded', [
        $first->id => -5,
        $second->id => 3,
    ]);

    expect($first->fresh()->files_downloaded)->toBe(0);
    expect($second->fresh()->files_downloaded)->toBe(3);
});

test('dashboard metrics include browse payloads for supported containers', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $downloaded = File::factory()->count(2)->create([
        'downloaded' => true,
        'source' => 'CivitAI',
    ]);

    $civitUser = Container::factory()->create([
        'type' => 'User',
        'source' => 'CivitAI',
        'source_id' => 'atlasUser',
        'referrer' => 'https://civitai.com/user/atlasUser',
    ]);

    $otherGallery = Container::factory()->create([
        'type' => 'Gallery',
        'source' => 'Booru',
        'source_id' => 'gallery-1',
        'referrer' => 'https://example.com/gallery/1',
    ]);

    $civitUser->files()->attach($downloaded->pluck('id'));
    $otherGallery->files()->attach($downloaded->take(1)->pluck('id'));

    app(MetricsService::class)->syncAll();

    $response = $this->getJson('/api/dashboard/metrics');

    $response->assertSuccessful();
    $topDownloads = $response->json('containers.top_downloads');

    expect($topDownloads)->toBeArray();
    expect($topDownloads[0]['browse_tab'])->toBe([
        'label' => 'CivitAI Images: User atlasUser - 1',
        'params' => [
            'feed' => 'online',
            'service' => 'civit-ai-images',
            'page' => 1,
            'limit' => 20,
            'username' => 'atlasUser',
        ],
    ]);
    expect(collect($topDownloads)->firstWhere('source', 'Booru')['browse_tab'])->toBeNull();
});
