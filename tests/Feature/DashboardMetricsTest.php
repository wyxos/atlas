<?php

use App\Models\Container;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Services\MetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('dashboard metrics report file and reaction totals', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $this->actingAs($user);

    $manualBlacklisted = File::factory()->create([
        'blacklisted_at' => now(),
        'downloaded' => true,
        'source' => 'local',
    ]);

    $autoDisliked = File::factory()->create([
        'blacklisted_at' => now(),
        'auto_disliked' => true,
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
    $containerGallery->files()->attach([$autoDisliked->id, $notFound->id]);
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
        'file_id' => $autoDisliked->id,
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
            'blacklisted' => 2,
            'auto_disliked' => 1,
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

test('incrementMetric clamps negative deltas without unsigned underflow', function () {
    $service = app(MetricsService::class);
    $key = MetricsService::KEY_FILES_UNREACTED_NOT_BLACKLISTED;

    $service->setMetric($key, 0);
    $service->incrementMetric($key, -1);
    expect((int) DB::table('metrics')->where('key', $key)->value('value'))->toBe(0);

    $service->setMetric($key, 2);
    $service->incrementMetric($key, -5);
    expect((int) DB::table('metrics')->where('key', $key)->value('value'))->toBe(0);
});

test('incrementContainersByCounts chunks large container batches', function () {
    $containers = Container::factory()->count(1001)->create([
        'files_downloaded' => 0,
    ]);

    $counts = [];
    foreach ($containers as $index => $container) {
        $counts[$container->id] = $index === 0 ? 2 : 1;
    }

    app(MetricsService::class)->incrementContainersByCounts('files_downloaded', $counts);

    expect($containers->first()->fresh()->files_downloaded)->toBe(2);
    expect($containers->last()->fresh()->files_downloaded)->toBe(1);
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

test('syncAll recomputes container counters across chunked batches', function () {
    $containers = Container::factory()->count(1001)->create([
        'type' => 'User',
        'source' => 'CivitAI',
    ]);

    $files = File::factory()->count(1001)->create([
        'downloaded' => true,
        'blacklisted_at' => null,
    ]);

    $user = User::factory()->create();

    foreach ($containers as $index => $container) {
        $file = $files[$index];
        $container->files()->attach($file->id);

        if ($index % 2 === 0) {
            $file->update(['blacklisted_at' => now()]);
        }

        if ($index % 3 === 0) {
            Reaction::create([
                'file_id' => $file->id,
                'user_id' => $user->id,
                'type' => 'love',
            ]);
        }
    }

    app(MetricsService::class)->syncAll();

    expect($containers->first()->fresh()->files_total)->toBe(1);
    expect($containers->first()->fresh()->files_downloaded)->toBe(1);
    expect($containers->first()->fresh()->files_blacklisted)->toBe(1);
    expect($containers->first()->fresh()->files_favorited)->toBe(1);

    expect($containers->last()->fresh()->files_total)->toBe(1);
    expect($containers->last()->fresh()->files_downloaded)->toBe(1);
    expect($containers->last()->fresh()->files_blacklisted)->toBe(1);
    expect($containers->last()->fresh()->files_favorited)->toBe(0);
});
