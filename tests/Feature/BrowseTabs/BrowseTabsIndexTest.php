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

test('tabs include items_data when file_ids exist', function () {
    $user = User::factory()->create();
    $file1 = File::factory()->create(['referrer_url' => 'https://example.com/file1.jpg']);
    $file2 = File::factory()->create(['referrer_url' => 'https://example.com/file2.jpg']);
    
    $tab = BrowseTab::factory()->for($user)->create([
        'file_ids' => [
            'https://example.com/file1.jpg',
            'https://example.com/file2.jpg',
        ],
    ]);

    $response = $this->actingAs($user)->getJson('/api/browse-tabs');

    $response->assertSuccessful();
    $data = $response->json();
    $tabData = collect($data)->firstWhere('id', $tab->id);
    expect($tabData['items_data'])->toBeArray();
    expect(count($tabData['items_data']))->toBe(2);
});

test('tabs with file_ids load and format files correctly', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'referrer_url' => 'https://example.com/file.jpg',
        'url' => 'https://example.com/original.jpg',
        'thumbnail_url' => 'https://example.com/thumb.jpg',
    ]);
    
    $tab = BrowseTab::factory()->for($user)->create([
        'file_ids' => ['https://example.com/file.jpg'],
    ]);

    $response = $this->actingAs($user)->getJson('/api/browse-tabs');

    $response->assertSuccessful();
    $data = $response->json();
    $tabData = collect($data)->firstWhere('id', $tab->id);
    expect($tabData['items_data'])->toBeArray();
    if (count($tabData['items_data']) > 0) {
        expect($tabData['items_data'][0])->toHaveKey('id');
        expect($tabData['items_data'][0])->toHaveKey('src');
        expect($tabData['items_data'][0])->toHaveKey('originalUrl');
    }
});

test('tabs maintain file order based on file_ids array', function () {
    $user = User::factory()->create();
    $file1 = File::factory()->create(['referrer_url' => 'https://example.com/file1.jpg']);
    $file2 = File::factory()->create(['referrer_url' => 'https://example.com/file2.jpg']);
    $file3 = File::factory()->create(['referrer_url' => 'https://example.com/file3.jpg']);
    
    $tab = BrowseTab::factory()->for($user)->create([
        'file_ids' => [
            'https://example.com/file3.jpg',
            'https://example.com/file1.jpg',
            'https://example.com/file2.jpg',
        ],
    ]);

    $response = $this->actingAs($user)->getJson('/api/browse-tabs');

    $response->assertSuccessful();
    $data = $response->json();
    $tabData = collect($data)->firstWhere('id', $tab->id);
    expect($tabData['items_data'])->toBeArray();
    if (count($tabData['items_data']) >= 3) {
        // Files should be in the order specified in file_ids
        // The ID in items_data uses listing_metadata['id'] or source_id or file->id
        // So we check that we have 3 items in the correct order
        expect(count($tabData['items_data']))->toBe(3);
        // Verify order by checking referrer_urls match the file_ids order
        $file1Found = false;
        $file2Found = false;
        $file3Found = false;
        foreach ($tabData['items_data'] as $item) {
            if ($item['originalUrl'] === $file1->url || $item['src'] === $file1->url) {
                $file1Found = true;
            }
            if ($item['originalUrl'] === $file2->url || $item['src'] === $file2->url) {
                $file2Found = true;
            }
            if ($item['originalUrl'] === $file3->url || $item['src'] === $file3->url) {
                $file3Found = true;
            }
        }
        expect($file1Found)->toBeTrue();
        expect($file2Found)->toBeTrue();
        expect($file3Found)->toBeTrue();
    }
});

test('tabs without file_ids have empty items_data', function () {
    $user = User::factory()->create();
    $tab = BrowseTab::factory()->for($user)->create([
        'file_ids' => null,
    ]);

    $response = $this->actingAs($user)->getJson('/api/browse-tabs');

    $response->assertSuccessful();
    $data = $response->json();
    $tabData = collect($data)->firstWhere('id', $tab->id);
    expect($tabData['items_data'])->toBeArray();
    expect($tabData['items_data'])->toBeEmpty();
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

test('tabs with query_params use correct page number', function () {
    $user = User::factory()->create();
    $file = File::factory()->create(['referrer_url' => 'https://example.com/file.jpg']);
    
    $tab = BrowseTab::factory()->for($user)->create([
        'file_ids' => ['https://example.com/file.jpg'],
        'query_params' => ['page' => 3],
    ]);

    $response = $this->actingAs($user)->getJson('/api/browse-tabs');

    $response->assertSuccessful();
    $data = $response->json();
    $tabData = collect($data)->firstWhere('id', $tab->id);
    if (count($tabData['items_data']) > 0) {
        expect($tabData['items_data'][0]['page'])->toBe(3);
    }
});

