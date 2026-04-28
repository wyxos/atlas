<?php

use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/BrowseIndexTestSupport.php';

uses(RefreshDatabase::class);

test('browse detaches all tab files when page is 1', function () {
    $user = User::factory()->create();
    $tab = \App\Models\Tab::factory()->for($user)->create();

    $existingFile = \App\Models\File::factory()->create();
    $tab->files()->attach($existingFile->id, ['position' => 0]);
    expect($tab->files()->count())->toBe(1);

    // Return zero items so we can assert detaching happens even when nothing new is attached.
    Http::fake([
        '*' => Http::response([
            'items' => [],
            'metadata' => [],
        ], 200),
    ]);

    $response = $this->actingAs($user)->getJson("/api/browse?source=civit-ai-images&page=1&tab_id={$tab->id}");
    $response->assertSuccessful();

    $tab->refresh();
    expect($tab->files()->count())->toBe(0);
});

test('browse does not detach tab files when page is not 1', function () {
    $user = User::factory()->create();
    $tab = \App\Models\Tab::factory()->for($user)->create();

    $existingFile = \App\Models\File::factory()->create();
    $tab->files()->attach($existingFile->id, ['position' => 0]);
    expect($tab->files()->count())->toBe(1);

    Http::fake([
        '*' => Http::response([
            'items' => [],
            'metadata' => [],
        ], 200),
    ]);

    $response = $this->actingAs($user)->getJson("/api/browse?source=civit-ai-images&page=2&tab_id={$tab->id}");
    $response->assertSuccessful();

    $tab->refresh();
    expect($tab->files()->count())->toBe(1);
});

test('browse persists current and next tokens for online tabs', function () {
    $user = User::factory()->create();
    $tab = \App\Models\Tab::factory()->for($user)->create();

    Http::fake([
        '*' => Http::response([
            'items' => [],
            'metadata' => [
                'nextCursor' => 'cursor-next',
            ],
        ], 200),
    ]);

    $response = $this->actingAs($user)->getJson("/api/browse?tab_id={$tab->id}&service=civit-ai-images&page=cursor-current");

    $response->assertSuccessful();

    $tab->refresh();
    expect($tab->params['page'])->toBe('cursor-current');
    expect($tab->params['next'])->toBe('cursor-next');
    expect($tab->params['feed'])->toBe('online');
});

test('browse persists current page token for local tabs', function () {
    $user = User::factory()->create();
    $tab = \App\Models\Tab::factory()->for($user)->create([
        'params' => ['feed' => 'local'],
    ]);

    $file = \App\Models\File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subDay(),
        'blacklisted_at' => null,
        'auto_disliked' => false,
        'source' => 'CivitAI',
    ]);

    mockLocalBrowseGateway([$file], nextCursor: 3, total: 3);

    $response = $this->actingAs($user)->getJson("/api/browse?tab_id={$tab->id}&feed=local&source=all&limit=1&page=2");

    $response->assertSuccessful();

    $tab->refresh();
    expect((string) $tab->params['page'])->toBe('2');
    expect($tab->params)->toHaveKey('next');
    expect($tab->params['feed'])->toBe('local');
    expect($file)->toBeInstanceOf(\App\Models\File::class);
});

test('browse persists local limit and preset params for tab restore', function () {
    $user = User::factory()->create();
    $tab = \App\Models\Tab::factory()->for($user)->create([
        'params' => [
            'feed' => 'local',
            // Simulate stale online cache from a previously browsed service.
            'service' => 'civit-ai-images',
            'serviceFiltersByKey' => [
                'civit-ai-images' => [
                    'page' => 1,
                    'limit' => 20,
                ],
            ],
        ],
    ]);

    \App\Models\File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subDay(),
        'blacklisted_at' => null,
        'auto_disliked' => false,
        'source' => 'CivitAI',
    ]);

    mockLocalBrowseGateway([], nextCursor: 51, total: 0);

    $response = $this->actingAs($user)->getJson("/api/browse?tab_id={$tab->id}&feed=local&source=all&limit=100&page=50&local_preset=inbox_fresh&reaction_mode=unreacted");

    $response->assertSuccessful();

    $tab->refresh();
    expect((string) ($tab->params['service'] ?? ''))->toBe('local');
    expect((string) $tab->params['feed'])->toBe('local');
    expect((string) $tab->params['page'])->toBe('50');
    expect((string) $tab->params['limit'])->toBe('100');
    expect((string) ($tab->params['local_preset'] ?? ''))->toBe('inbox_fresh');
    expect((string) ($tab->params['reaction_mode'] ?? ''))->toBe('unreacted');
});

test('browse persists unreacted random preset params for tab restore', function () {
    $user = User::factory()->create();
    $tab = \App\Models\Tab::factory()->for($user)->create([
        'params' => [
            'feed' => 'local',
        ],
    ]);

    \App\Models\File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subDay(),
        'blacklisted_at' => null,
        'auto_disliked' => false,
        'source' => 'CivitAI',
    ]);

    mockLocalBrowseGateway([], nextCursor: 4, total: 0);

    $response = $this->actingAs($user)->getJson("/api/browse?tab_id={$tab->id}&feed=local&source=all&limit=20&page=3&local_preset=unreacted_random&reaction_mode=unreacted&sort=random&seed=12345&blacklisted=no&auto_disliked=no");

    $response->assertSuccessful();

    $tab->refresh();
    expect((string) ($tab->params['service'] ?? ''))->toBe('local');
    expect((string) $tab->params['feed'])->toBe('local');
    expect((string) $tab->params['page'])->toBe('3');
    expect((string) $tab->params['limit'])->toBe('20');
    expect((string) ($tab->params['local_preset'] ?? ''))->toBe('unreacted_random');
    expect((string) ($tab->params['reaction_mode'] ?? ''))->toBe('unreacted');
    expect((string) ($tab->params['sort'] ?? ''))->toBe('random');
    expect((string) ($tab->params['seed'] ?? ''))->toBe('12345');
    expect((string) ($tab->params['blacklisted'] ?? ''))->toBe('no');
    expect((string) ($tab->params['auto_disliked'] ?? ''))->toBe('no');
});

test('local reaction_at dislike browse returns typesense totals and keeps reaction order', function () {
    $user = User::factory()->create();
    $tab = \App\Models\Tab::factory()->for($user)->create([
        'params' => ['feed' => 'local'],
    ]);

    $older = \App\Models\File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subDays(2),
        'blacklisted_at' => null,
        'auto_disliked' => false,
        'source' => 'CivitAI',
        'previewed_count' => 1,
    ]);

    $newer = \App\Models\File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subDay(),
        'blacklisted_at' => null,
        'auto_disliked' => false,
        'source' => 'Wallhaven',
        'previewed_count' => 1,
    ]);

    Reaction::create([
        'file_id' => $older->id,
        'user_id' => $user->id,
        'type' => 'dislike',
    ])->update(['created_at' => now()->subHours(6), 'updated_at' => now()->subHours(6)]);

    Reaction::create([
        'file_id' => $newer->id,
        'user_id' => $user->id,
        'type' => 'dislike',
    ])->update(['created_at' => now()->subHours(1), 'updated_at' => now()->subHours(1)]);

    mockLocalBrowseGateway([$newer, $older], nextCursor: null, total: 2);

    $response = $this->actingAs($user)->getJson("/api/browse?tab_id={$tab->id}&feed=local&source=all&limit=20&reaction_mode=types&reaction[]=dislike&sort=reaction_at&blacklisted=no&auto_disliked=no&max_previewed_count=2");

    $response->assertSuccessful();

    $data = $response->json();
    expect($data['items'])->toBeArray();
    expect($data['items'])->not->toBeEmpty();
    expect($data['items'][0]['id'])->toBe($newer->id);
    expect($data['total'])->toBe(2);
});

test('local reaction_at dislike browse can include total count when requested', function () {
    $user = User::factory()->create();
    $tab = \App\Models\Tab::factory()->for($user)->create([
        'params' => ['feed' => 'local'],
    ]);

    $older = \App\Models\File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subDays(2),
        'blacklisted_at' => null,
        'auto_disliked' => false,
        'source' => 'CivitAI',
        'previewed_count' => 1,
    ]);

    $newer = \App\Models\File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subDay(),
        'blacklisted_at' => null,
        'auto_disliked' => false,
        'source' => 'Wallhaven',
        'previewed_count' => 1,
    ]);

    Reaction::create([
        'file_id' => $older->id,
        'user_id' => $user->id,
        'type' => 'dislike',
    ])->update(['created_at' => now()->subHours(6), 'updated_at' => now()->subHours(6)]);

    Reaction::create([
        'file_id' => $newer->id,
        'user_id' => $user->id,
        'type' => 'dislike',
    ])->update(['created_at' => now()->subHours(1), 'updated_at' => now()->subHours(1)]);

    mockLocalBrowseGateway([$newer, $older], nextCursor: null, total: 2);

    $response = $this->actingAs($user)->getJson("/api/browse?tab_id={$tab->id}&feed=local&source=all&limit=20&reaction_mode=types&reaction[]=dislike&sort=reaction_at&blacklisted=no&auto_disliked=no&max_previewed_count=2&include_total=1");

    $response->assertSuccessful();

    $data = $response->json();
    expect($data['items'])->toBeArray();
    expect($data['items'])->not->toBeEmpty();
    expect($data['items'][0]['id'])->toBe($newer->id);
    expect($data['total'])->toBe(2);
});
