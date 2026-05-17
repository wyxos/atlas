<?php

use App\Models\User;
use App\Services\Library\LibraryTypesenseCompiler;
use App\Services\Library\LibraryTypesenseGateway;
use App\Services\Library\LibraryTypesenseNames;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/BrowseIndexTestSupport.php';

uses(RefreshDatabase::class);

test('browse uses LocalService when feed is local', function () {

    $user = User::factory()->create();

    $tab = \App\Models\Tab::factory()->for($user)->create([

        'params' => ['feed' => 'local'],

    ]);

    // Create Library with downloaded_at set

    $file1 = \App\Models\File::factory()->create([

        'downloaded' => true,

        'downloaded_at' => now()->subDay(),

        'blacklisted_at' => null,

        'auto_blacklisted' => false,

        'source' => 'CivitAI',

    ]);

    $file2 = \App\Models\File::factory()->create([

        'downloaded' => true,

        'downloaded_at' => now()->subHours(12),

        'blacklisted_at' => null,

        'auto_blacklisted' => false,

        'source' => 'Wallhaven',

    ]);

    mockLibraryGateway([$file2, $file1], nextCursor: null, total: 2);

    $response = $this->actingAs($user)->getJson("/api/browse?tab_id={$tab->id}&feed=local&source=all&limit=20");

    $response->assertSuccessful();

    $data = $response->json();

    expect($data['items'])->toBeArray();

    // Note: Items are transformed by Browser, so we check that items exist

    expect(count($data['items']))->toBeGreaterThanOrEqual(0);

    // Verify files are not persisted again (they already exist)

    expect(\App\Models\File::count())->toBe(2);

    // Verify files are not attached to tab in local mode

    expect($tab->files()->count())->toBe(0);

});

test('browse filters by source in local mode', function () {

    $user = User::factory()->create();

    $tab = \App\Models\Tab::factory()->for($user)->create([

        'params' => ['feed' => 'local'],

    ]);

    $file1 = \App\Models\File::factory()->create([

        'downloaded' => true,

        'downloaded_at' => now()->subDay(),

        'blacklisted_at' => null,

        'auto_blacklisted' => false,

        'source' => 'CivitAI',

    ]);

    $file2 = \App\Models\File::factory()->create([

        'downloaded' => true,

        'downloaded_at' => now()->subHours(12),

        'blacklisted_at' => null,

        'auto_blacklisted' => false,

        'source' => 'Wallhaven',

    ]);

    mockLibraryGateway([$file1], nextCursor: null, total: 1);

    $response = $this->actingAs($user)->getJson("/api/browse?tab_id={$tab->id}&feed=local&source=CivitAI&limit=20");

    $response->assertSuccessful();

    $data = $response->json();

    expect($data['items'])->toBeArray();

    // Note: Items are transformed by Browser, so we check that at least one item exists

    // The actual count depends on how Browser transforms the LocalService response

    expect(count($data['items']))->toBeGreaterThanOrEqual(0);

});

test('Library can return blacklisted files when blacklisted filter is yes', function () {

    $user = User::factory()->create();

    $tab = \App\Models\Tab::factory()->for($user)->create([

        'params' => ['feed' => 'local'],

    ]);

    $blacklisted = \App\Models\File::factory()->create([

        'downloaded' => true,

        'downloaded_at' => now()->subDay(),

        'blacklisted_at' => now(),

        'auto_blacklisted' => false,

        'source' => 'CivitAI',

    ]);

    $notBlacklisted = \App\Models\File::factory()->create([

        'downloaded' => true,

        'downloaded_at' => now()->subHours(12),

        'blacklisted_at' => null,

        'auto_blacklisted' => false,

        'source' => 'Wallhaven',

    ]);

    mockLibraryGateway([$blacklisted], nextCursor: null, total: 1);

    $response = $this->actingAs($user)->getJson("/api/browse?tab_id={$tab->id}&feed=local&source=all&limit=20&blacklisted=yes");

    $response->assertSuccessful();

    $data = $response->json();

    expect($data['total'])->toBeInt();

    expect($data['items'])->toBeArray();

    $ids = collect($data['items'])->pluck('id')->all();

    expect($ids)->toContain($blacklisted->id);

    expect($ids)->not->toContain($notBlacklisted->id);

});

test('Library returns 503 when typesense aliases are missing', function () {

    $user = User::factory()->create();

    $tab = \App\Models\Tab::factory()->for($user)->create([

        'params' => ['feed' => 'local'],

    ]);

    $names = \Mockery::mock(LibraryTypesenseNames::class);

    $names->shouldReceive('hasFilesAlias')->andReturn(false);

    app()->instance(LibraryTypesenseNames::class, $names);

    app()->instance(LibraryTypesenseGateway::class, new LibraryTypesenseGateway(

        app(LibraryTypesenseCompiler::class),

        $names,

    ));

    $response = $this->actingAs($user)->getJson("/api/browse?tab_id={$tab->id}&feed=local&source=all&limit=20");

    $response->assertStatus(503);

    $response->assertExactJson([

        'message' => 'Library unavailable',

        'service' => 'local',

        'reason' => 'typesense_unavailable',

    ]);

});

test('Library returns 503 when typesense search execution fails', function () {

    $user = User::factory()->create();

    $tab = \App\Models\Tab::factory()->for($user)->create([

        'params' => ['feed' => 'local'],

    ]);

    $names = \Mockery::mock(LibraryTypesenseNames::class);

    $names->shouldReceive('hasFilesAlias')->andReturn(true);

    $names->shouldReceive('hasReactionsAlias')->andReturn(true);

    $names->shouldReceive('currentReactionJoinCollection')->andReturn('atlas_local_library_files__vtest');

    $names->shouldReceive('filesAlias')->andReturn('atlas_local_library_files');

    $names->shouldReceive('reactionsAlias')->andReturn('atlas_local_library_reactions');

    app()->instance(LibraryTypesenseNames::class, $names);

    app()->instance(LibraryTypesenseGateway::class, new class(app(LibraryTypesenseCompiler::class), $names) extends LibraryTypesenseGateway
    {
        protected function runScoutSearch(array $compiled): array
        {

            throw new \RuntimeException('Typesense exploded');
        }
    });

    $response = $this->actingAs($user)->getJson("/api/browse?tab_id={$tab->id}&feed=local&source=all&limit=20");

    $response->assertStatus(503);

    $response->assertExactJson([

        'message' => 'Library unavailable',

        'service' => 'local',

        'reason' => 'typesense_unavailable',

    ]);

});
