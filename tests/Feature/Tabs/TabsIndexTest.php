<?php

use App\Models\File;
use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated user can view their browse tabs', function () {
    $user = User::factory()->create();
    Tab::factory()->for($user)->count(3)->create();

    $response = $this->actingAs($user)->getJson(route('api.tabs.index'));

    $response->assertSuccessful();
    $data = $response->json();
    expect($data)->toBeArray();
    expect(count($data))->toBe(3);
});

test('tabs are returned ordered by position', function () {
    $user = User::factory()->create();
    $tab1 = Tab::factory()->for($user)->create(['position' => 2]);
    $tab2 = Tab::factory()->for($user)->create(['position' => 0]);
    $tab3 = Tab::factory()->for($user)->create(['position' => 1]);

    $response = $this->actingAs($user)->getJson(route('api.tabs.index'));

    $response->assertSuccessful();
    $data = $response->json();
    expect($data[0]['id'])->toBe($tab2->id);
    expect($data[1]['id'])->toBe($tab3->id);
    expect($data[2]['id'])->toBe($tab1->id);
});

test('tabs do not include items_data in index response (lazy loading)', function () {
    $user = User::factory()->create();
    $file1 = File::factory()->create(['referrer_url' => 'https://example.com/file1.jpg']);
    $file2 = File::factory()->create(['referrer_url' => 'https://example.com/file2.jpg']);

    $tab = Tab::factory()->for($user)->withFiles([$file1->id, $file2->id])->create();

    $response = $this->actingAs($user)->getJson(route('api.tabs.index'));

    $response->assertSuccessful();
    $data = $response->json();
    $tabData = collect($data)->firstWhere('id', $tab->id);
    // items_data should NOT be included in index response (for performance with 1000+ tabs)
    expect($tabData)->not->toHaveKey('items_data');
    // has_files should NOT be included - frontend will check by calling items endpoint
    expect($tabData)->not->toHaveKey('has_files');
});

test('tabs do not include file-related data in index response', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'referrer_url' => 'https://example.com/file.jpg',
        'url' => 'https://example.com/original.jpg',
        'thumbnail_url' => 'https://example.com/thumb.jpg',
    ]);

    $tab = Tab::factory()->for($user)->withFiles([$file->id])->create();

    $response = $this->actingAs($user)->getJson(route('api.tabs.index'));

    $response->assertSuccessful();
    $data = $response->json();
    $tabData = collect($data)->firstWhere('id', $tab->id);
    // items_data should NOT be included
    expect($tabData)->not->toHaveKey('items_data');
    // has_files should NOT be included - frontend will check by calling items endpoint
    expect($tabData)->not->toHaveKey('has_files');
});

test('tabs do not include file-related data regardless of file count', function () {
    $user = User::factory()->create();
    $file1 = File::factory()->create(['referrer_url' => 'https://example.com/file1.jpg']);
    $file2 = File::factory()->create(['referrer_url' => 'https://example.com/file2.jpg']);
    $file3 = File::factory()->create(['referrer_url' => 'https://example.com/file3.jpg']);

    // Create tab with files in specific order: file3, file1, file2
    $tab = Tab::factory()->for($user)->withFiles([$file3->id, $file1->id, $file2->id])->create();

    $response = $this->actingAs($user)->getJson(route('api.tabs.index'));

    $response->assertSuccessful();
    $data = $response->json();
    $tabData = collect($data)->firstWhere('id', $tab->id);
    // items_data should NOT be included
    expect($tabData)->not->toHaveKey('items_data');
    // has_files should NOT be included - frontend will check by calling items endpoint
    expect($tabData)->not->toHaveKey('has_files');
});

test('tabs without files do not include file-related data', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create();

    $response = $this->actingAs($user)->getJson(route('api.tabs.index'));

    $response->assertSuccessful();
    $data = $response->json();
    $tabData = collect($data)->firstWhere('id', $tab->id);
    // items_data should NOT be included
    expect($tabData)->not->toHaveKey('items_data');
    // has_files should NOT be included - frontend will check by calling items endpoint
    expect($tabData)->not->toHaveKey('has_files');
});

test('user only sees their own tabs', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    Tab::factory()->for($user1)->count(2)->create();
    Tab::factory()->for($user2)->count(3)->create();

    $response = $this->actingAs($user1)->getJson('/api/tabs');

    $response->assertSuccessful();
    $data = $response->json();
    expect(count($data))->toBe(2);
    foreach ($data as $tab) {
        expect($tab['user_id'])->toBe($user1->id);
    }
});

test('guest cannot view browse tabs', function () {
    $response = $this->getJson(route('api.tabs.index'));

    $response->assertUnauthorized();
});

test('tabs include params in index response', function () {
    $user = User::factory()->create();
    $file = File::factory()->create(['referrer_url' => 'https://example.com/file.jpg']);

    $tab = Tab::factory()->for($user)
        ->withParams(['page' => 3, 'next' => 'cursor-123'])
        ->withFiles([$file->id])
        ->create();

    $response = $this->actingAs($user)->getJson(route('api.tabs.index'));

    $response->assertSuccessful();
    $data = $response->json();
    $tabData = collect($data)->firstWhere('id', $tab->id);
    expect($tabData['params'])->toBeArray();
    expect($tabData['params']['page'])->toBe(3);
    expect($tabData['params']['next'])->toBe('cursor-123');
    // items_data should NOT be included
    expect($tabData)->not->toHaveKey('items_data');
});
