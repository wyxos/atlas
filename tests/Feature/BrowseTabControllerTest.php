<?php

use App\Models\BrowseTab;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('returns all tabs for authenticated user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    // Create tabs for the user
    $tab1 = BrowseTab::factory()->for($user)->create(['position' => 0]);
    $tab2 = BrowseTab::factory()->for($user)->create(['position' => 1]);
    // Create tab for other user (should not appear)
    BrowseTab::factory()->for($otherUser)->create();

    $response = $this->actingAs($user)
        ->getJson('/api/browse-tabs');

    $response->assertSuccessful()
        ->assertJsonCount(2);

    $tabs = $response->json();
    expect($tabs[0]['id'])->toBe($tab1->id);
    expect($tabs[1]['id'])->toBe($tab2->id);
});

it('returns tabs ordered by position', function () {
    $user = User::factory()->create();

    $tab3 = BrowseTab::factory()->for($user)->create(['position' => 2]);
    $tab1 = BrowseTab::factory()->for($user)->create(['position' => 0]);
    $tab2 = BrowseTab::factory()->for($user)->create(['position' => 1]);

    $response = $this->actingAs($user)
        ->getJson('/api/browse-tabs');

    $response->assertSuccessful();
    $tabs = $response->json();
    expect($tabs[0]['id'])->toBe($tab1->id);
    expect($tabs[1]['id'])->toBe($tab2->id);
    expect($tabs[2]['id'])->toBe($tab3->id);
});

it('eager loads and formats files when tab has file_ids', function () {
    $user = User::factory()->create();

    // Create files with referrer URLs
    $file1 = File::factory()->create([
        'referrer_url' => 'https://civitai.com/images/123',
        'thumbnail_url' => 'https://civitai.com/images/123/thumbnail.jpg',
        'url' => 'https://civitai.com/images/123/original.jpg',
        'mime_type' => 'image/jpeg',
        'listing_metadata' => ['id' => '123'],
    ]);

    $file2 = File::factory()->create([
        'referrer_url' => 'https://civitai.com/images/456',
        'thumbnail_url' => 'https://civitai.com/images/456/thumbnail.jpg',
        'url' => 'https://civitai.com/images/456/original.jpg',
        'mime_type' => 'image/png',
        'listing_metadata' => ['id' => '456'],
    ]);

    // Create metadata for files
    FileMetadata::factory()->for($file1)->create([
        'payload' => ['width' => 1024, 'height' => 768],
    ]);
    FileMetadata::factory()->for($file2)->create([
        'payload' => ['width' => 1920, 'height' => 1080],
    ]);

    // Create tab with file_ids (referrer URLs)
    $tab = BrowseTab::factory()->for($user)->create([
        'file_ids' => [
            'https://civitai.com/images/123',
            'https://civitai.com/images/456',
        ],
        'query_params' => ['page' => 5, 'next' => '10|1234567890'],
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/browse-tabs');

    $response->assertSuccessful();
    $tabs = $response->json();
    $returnedTab = collect($tabs)->firstWhere('id', $tab->id);

    expect($returnedTab)->not->toBeNull();
    expect($returnedTab['items_data'])->toBeArray();
    expect($returnedTab['items_data'])->toHaveCount(2);

    // Verify first item structure
    $item1 = $returnedTab['items_data'][0];
    expect($item1)->toHaveKeys(['id', 'width', 'height', 'src', 'originalUrl', 'thumbnail', 'type', 'page', 'index', 'notFound']);
    expect($item1['id'])->toBe('123');
    expect($item1['width'])->toBe(1024);
    expect($item1['height'])->toBe(768);
    expect($item1['src'])->toBe('https://civitai.com/images/123/thumbnail.jpg'); // Should use thumbnail
    expect($item1['originalUrl'])->toBe('https://civitai.com/images/123/original.jpg');
    expect($item1['type'])->toBe('image');
    expect($item1['page'])->toBe(5); // Should use page from query_params

    // Verify second item
    $item2 = $returnedTab['items_data'][1];
    expect($item2['id'])->toBe('456');
    expect($item2['width'])->toBe(1920);
    expect($item2['height'])->toBe(1080);
});

it('maintains file order based on file_ids array', function () {
    $user = User::factory()->create();

    $file1 = File::factory()->create(['referrer_url' => 'https://civitai.com/images/1']);
    $file2 = File::factory()->create(['referrer_url' => 'https://civitai.com/images/2']);
    $file3 = File::factory()->create(['referrer_url' => 'https://civitai.com/images/3']);

    // Create tab with file_ids in specific order
    $tab = BrowseTab::factory()->for($user)->create([
        'file_ids' => [
            'https://civitai.com/images/3',
            'https://civitai.com/images/1',
            'https://civitai.com/images/2',
        ],
        'query_params' => ['page' => 1],
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/browse-tabs');

    $response->assertSuccessful();
    $tabs = $response->json();
    $returnedTab = collect($tabs)->firstWhere('id', $tab->id);

    expect($returnedTab['items_data'])->toHaveCount(3);
    // Verify order matches file_ids array
    expect($returnedTab['items_data'][0]['id'])->toBe((string) ($file3->listing_metadata['id'] ?? $file3->source_id ?? $file3->id));
    expect($returnedTab['items_data'][1]['id'])->toBe((string) ($file1->listing_metadata['id'] ?? $file1->source_id ?? $file1->id));
    expect($returnedTab['items_data'][2]['id'])->toBe((string) ($file2->listing_metadata['id'] ?? $file2->source_id ?? $file2->id));
});

it('returns empty items_data when tab has no file_ids', function () {
    $user = User::factory()->create();

    $tab = BrowseTab::factory()->for($user)->create([
        'file_ids' => null,
        'query_params' => ['page' => 1],
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/browse-tabs');

    $response->assertSuccessful();
    $tabs = $response->json();
    $returnedTab = collect($tabs)->firstWhere('id', $tab->id);

    expect($returnedTab['items_data'])->toBe([]);
});

it('returns empty items_data when file_ids is empty array', function () {
    $user = User::factory()->create();

    $tab = BrowseTab::factory()->for($user)->create([
        'file_ids' => [],
        'query_params' => ['page' => 1],
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/browse-tabs');

    $response->assertSuccessful();
    $tabs = $response->json();
    $returnedTab = collect($tabs)->firstWhere('id', $tab->id);

    expect($returnedTab['items_data'])->toBe([]);
});

it('defaults to page 1 when query_params has no page', function () {
    $user = User::factory()->create();

    $file = File::factory()->create([
        'referrer_url' => 'https://civitai.com/images/123',
        'listing_metadata' => ['id' => '123'],
    ]);

    $tab = BrowseTab::factory()->for($user)->create([
        'file_ids' => ['https://civitai.com/images/123'],
        'query_params' => [], // No page in query_params
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/browse-tabs');

    $response->assertSuccessful();
    $tabs = $response->json();
    $returnedTab = collect($tabs)->firstWhere('id', $tab->id);

    expect($returnedTab['items_data'])->toHaveCount(1);
    expect($returnedTab['items_data'][0]['page'])->toBe(1);
});

it('creates a new browse tab', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/browse-tabs', [
            'label' => 'My Tab',
            'query_params' => ['page' => 1, 'next' => '2|1234567890'],
            'file_ids' => ['https://civitai.com/images/123'],
            'position' => 0,
        ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'id',
            'user_id',
            'label',
            'query_params',
            'file_ids',
            'position',
        ]);

    expect($response->json('label'))->toBe('My Tab');
    expect($response->json('query_params'))->toBe(['page' => 1, 'next' => '2|1234567890']);
    expect($response->json('file_ids'))->toBe(['https://civitai.com/images/123']);

    // Verify tab was created in database
    $tab = BrowseTab::find($response->json('id'));
    expect($tab)->not->toBeNull();
    expect($tab->user_id)->toBe($user->id);
});

it('auto-assigns position when creating tab', function () {
    $user = User::factory()->create();

    BrowseTab::factory()->for($user)->create(['position' => 5]);

    $response = $this->actingAs($user)
        ->postJson('/api/browse-tabs', [
            'label' => 'New Tab',
            'query_params' => [],
            'file_ids' => [],
        ]);

    $response->assertCreated();
    expect($response->json('position'))->toBe(6); // Should be max + 1
});

it('validates required fields when creating tab', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/browse-tabs', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['label']);
});

it('validates label max length when creating tab', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/browse-tabs', [
            'label' => str_repeat('a', 256), // Exceeds max 255
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['label']);
});

it('updates a browse tab', function () {
    $user = User::factory()->create();
    $tab = BrowseTab::factory()->for($user)->create([
        'label' => 'Old Label',
        'query_params' => ['page' => 1],
    ]);

    $response = $this->actingAs($user)
        ->putJson("/api/browse-tabs/{$tab->id}", [
            'label' => 'New Label',
            'query_params' => ['page' => 5, 'next' => '10|1234567890'],
            'file_ids' => ['https://civitai.com/images/123'],
        ]);

    $response->assertSuccessful();
    expect($response->json('label'))->toBe('New Label');
    expect($response->json('query_params'))->toBe(['page' => 5, 'next' => '10|1234567890']);

    $tab->refresh();
    expect($tab->label)->toBe('New Label');
});

it('prevents updating other users tabs', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $tab = BrowseTab::factory()->for($otherUser)->create();

    $response = $this->actingAs($user)
        ->putJson("/api/browse-tabs/{$tab->id}", [
            'label' => 'Hacked Label',
        ]);

    $response->assertForbidden();
});

it('deletes a browse tab', function () {
    $user = User::factory()->create();
    $tab = BrowseTab::factory()->for($user)->create();

    $response = $this->actingAs($user)
        ->deleteJson("/api/browse-tabs/{$tab->id}");

    $response->assertSuccessful()
        ->assertJson(['message' => 'Tab deleted successfully']);

    expect(BrowseTab::find($tab->id))->toBeNull();
});

it('prevents deleting other users tabs', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $tab = BrowseTab::factory()->for($otherUser)->create();

    $response = $this->actingAs($user)
        ->deleteJson("/api/browse-tabs/{$tab->id}");

    $response->assertForbidden();
});

it('updates tab position', function () {
    $user = User::factory()->create();
    $tab = BrowseTab::factory()->for($user)->create(['position' => 0]);

    $response = $this->actingAs($user)
        ->patchJson("/api/browse-tabs/{$tab->id}/position", [
            'position' => 5,
        ]);

    $response->assertSuccessful();
    expect($response->json('position'))->toBe(5);

    $tab->refresh();
    expect($tab->position)->toBe(5);
});

it('requires authentication to access tabs', function () {
    $response = $this->getJson('/api/browse-tabs');
    $response->assertUnauthorized();
});

it('uses thumbnail_url for src when available, falls back to url', function () {
    $user = User::factory()->create();

    $fileWithThumbnail = File::factory()->create([
        'referrer_url' => 'https://civitai.com/images/123',
        'thumbnail_url' => 'https://civitai.com/images/123/thumb.jpg',
        'url' => 'https://civitai.com/images/123/original.jpg',
        'listing_metadata' => ['id' => '123'],
    ]);

    $fileWithoutThumbnail = File::factory()->create([
        'referrer_url' => 'https://civitai.com/images/456',
        'thumbnail_url' => null,
        'url' => 'https://civitai.com/images/456/original.jpg',
        'listing_metadata' => ['id' => '456'],
    ]);

    $tab = BrowseTab::factory()->for($user)->create([
        'file_ids' => [
            'https://civitai.com/images/123',
            'https://civitai.com/images/456',
        ],
        'query_params' => ['page' => 1],
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/browse-tabs');

    $response->assertSuccessful();
    $tabs = $response->json();
    $returnedTab = collect($tabs)->firstWhere('id', $tab->id);

    $item1 = collect($returnedTab['items_data'])->firstWhere('id', '123');
    expect($item1['src'])->toBe('https://civitai.com/images/123/thumb.jpg');

    $item2 = collect($returnedTab['items_data'])->firstWhere('id', '456');
    expect($item2['src'])->toBe('https://civitai.com/images/456/original.jpg');
});

it('determines type as video when mime_type starts with video/', function () {
    $user = User::factory()->create();

    $videoFile = File::factory()->create([
        'referrer_url' => 'https://civitai.com/images/123',
        'mime_type' => 'video/mp4',
        'listing_metadata' => ['id' => '123'],
    ]);

    $imageFile = File::factory()->create([
        'referrer_url' => 'https://civitai.com/images/456',
        'mime_type' => 'image/jpeg',
        'listing_metadata' => ['id' => '456'],
    ]);

    $tab = BrowseTab::factory()->for($user)->create([
        'file_ids' => [
            'https://civitai.com/images/123',
            'https://civitai.com/images/456',
        ],
        'query_params' => ['page' => 1],
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/browse-tabs');

    $response->assertSuccessful();
    $tabs = $response->json();
    $returnedTab = collect($tabs)->firstWhere('id', $tab->id);

    $videoItem = collect($returnedTab['items_data'])->firstWhere('id', '123');
    expect($videoItem['type'])->toBe('video');

    $imageItem = collect($returnedTab['items_data'])->firstWhere('id', '456');
    expect($imageItem['type'])->toBe('image');
});
