<?php

use App\Models\BrowseTab;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated user can view their browse tabs', function () {
    $user = User::factory()->create();
    BrowseTab::factory()->for($user)->count(3)->create();

    $response = $this->actingAs($user)->getJson('/api/browse-tabs');

    $response->assertSuccessful();
    $data = $response->json();
    expect($data)->toBeArray();
    expect(count($data))->toBe(3);
});

test('tabs are returned ordered by position', function () {
    $user = User::factory()->create();
    $tab1 = BrowseTab::factory()->for($user)->create(['position' => 2]);
    $tab2 = BrowseTab::factory()->for($user)->create(['position' => 0]);
    $tab3 = BrowseTab::factory()->for($user)->create(['position' => 1]);

    $response = $this->actingAs($user)->getJson('/api/browse-tabs');

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
    
    $tab = BrowseTab::factory()->for($user)->withFiles([$file1->id, $file2->id])->create();

    $response = $this->actingAs($user)->getJson('/api/browse-tabs');

    $response->assertSuccessful();
    $data = $response->json();
    $tabData = collect($data)->firstWhere('id', $tab->id);
    // items_data should NOT be included in index response (for performance with 1000+ tabs)
    expect($tabData)->not->toHaveKey('items_data');
    // But file_ids should be included
    expect($tabData['file_ids'])->toBeArray();
    expect(count($tabData['file_ids']))->toBe(2);
});

test('tabs include file_ids when files exist', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'referrer_url' => 'https://example.com/file.jpg',
        'url' => 'https://example.com/original.jpg',
        'thumbnail_url' => 'https://example.com/thumb.jpg',
    ]);
    
    $tab = BrowseTab::factory()->for($user)->withFiles([$file->id])->create();

    $response = $this->actingAs($user)->getJson('/api/browse-tabs');

    $response->assertSuccessful();
    $data = $response->json();
    $tabData = collect($data)->firstWhere('id', $tab->id);
    // file_ids should be included
    expect($tabData['file_ids'])->toBeArray();
    expect($tabData['file_ids'][0])->toBe($file->id);
    // items_data should NOT be included
    expect($tabData)->not->toHaveKey('items_data');
});

test('tabs maintain file_ids order based on pivot position', function () {
    $user = User::factory()->create();
    $file1 = File::factory()->create(['referrer_url' => 'https://example.com/file1.jpg']);
    $file2 = File::factory()->create(['referrer_url' => 'https://example.com/file2.jpg']);
    $file3 = File::factory()->create(['referrer_url' => 'https://example.com/file3.jpg']);
    
    // Create tab with files in specific order: file3, file1, file2
    $tab = BrowseTab::factory()->for($user)->withFiles([$file3->id, $file1->id, $file2->id])->create();

    $response = $this->actingAs($user)->getJson('/api/browse-tabs');

    $response->assertSuccessful();
    $data = $response->json();
    $tabData = collect($data)->firstWhere('id', $tab->id);
    // file_ids should maintain order
    expect($tabData['file_ids'])->toBeArray();
    expect(count($tabData['file_ids']))->toBe(3);
    // Verify order by checking file_ids matches the order we specified
    expect($tabData['file_ids'][0])->toBe($file3->id);
    expect($tabData['file_ids'][1])->toBe($file1->id);
    expect($tabData['file_ids'][2])->toBe($file2->id);
    // items_data should NOT be included
    expect($tabData)->not->toHaveKey('items_data');
});

test('tabs without files have empty file_ids', function () {
    $user = User::factory()->create();
    $tab = BrowseTab::factory()->for($user)->create();

    $response = $this->actingAs($user)->getJson('/api/browse-tabs');

    $response->assertSuccessful();
    $data = $response->json();
    $tabData = collect($data)->firstWhere('id', $tab->id);
    expect($tabData['file_ids'])->toBeArray();
    expect($tabData['file_ids'])->toBeEmpty();
    // items_data should NOT be included
    expect($tabData)->not->toHaveKey('items_data');
});

test('user only sees their own tabs', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    
    BrowseTab::factory()->for($user1)->count(2)->create();
    BrowseTab::factory()->for($user2)->count(3)->create();

    $response = $this->actingAs($user1)->getJson('/api/browse-tabs');

    $response->assertSuccessful();
    $data = $response->json();
    expect(count($data))->toBe(2);
    foreach ($data as $tab) {
        expect($tab['user_id'])->toBe($user1->id);
    }
});

test('guest cannot view browse tabs', function () {
    $response = $this->getJson('/api/browse-tabs');

    $response->assertUnauthorized();
});

test('tabs include query_params in index response', function () {
    $user = User::factory()->create();
    $file = File::factory()->create(['referrer_url' => 'https://example.com/file.jpg']);
    
    $tab = BrowseTab::factory()->for($user)
        ->withQueryParams(['page' => 3, 'next' => 'cursor-123'])
        ->withFiles([$file->id])
        ->create();

    $response = $this->actingAs($user)->getJson('/api/browse-tabs');

    $response->assertSuccessful();
    $data = $response->json();
    $tabData = collect($data)->firstWhere('id', $tab->id);
    expect($tabData['query_params'])->toBeArray();
    expect($tabData['query_params']['page'])->toBe(3);
    expect($tabData['query_params']['next'])->toBe('cursor-123');
    // items_data should NOT be included
    expect($tabData)->not->toHaveKey('items_data');
});

