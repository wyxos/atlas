<?php

use App\Models\File;
use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated user can load items for their tab', function () {
    $user = User::factory()->create();
    $file1 = File::factory()->create(['referrer_url' => 'https://example.com/file1.jpg']);
    $file2 = File::factory()->create(['referrer_url' => 'https://example.com/file2.jpg']);

    $tab = Tab::factory()->for($user)->withFiles([$file1->id, $file2->id])->create();

    $response = $this->actingAs($user)->getJson(route('api.tabs.show', ['tab' => $tab->id]));

    $response->assertSuccessful();
    $data = $response->json();
    expect($data)->toHaveKey('tab');
    expect($data['tab'])->toHaveKey('items');
    expect($data['tab']['items'])->toBeArray();
    expect(count($data['tab']['items']))->toBe(2);
});

test('items are formatted correctly', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'referrer_url' => 'https://example.com/file.jpg',
        'url' => 'https://example.com/original.jpg',
        'preview_url' => 'https://example.com/thumb.jpg',
    ]);

    $tab = Tab::factory()->for($user)->withFiles([$file->id])->create();

    $response = $this->actingAs($user)->getJson(route('api.tabs.show', ['tab' => $tab->id]));

    $response->assertSuccessful();
    $data = $response->json();
    expect($data)->toHaveKey('tab');
    expect($data['tab'])->toHaveKey('items');
    expect($data['tab']['items'])->toBeArray();
    expect(count($data['tab']['items']))->toBe(1);

    $item = $data['tab']['items'][0];
    expect($item)->toHaveKey('id');
    expect($item['id'])->toBe($file->id);
    expect($item)->toHaveKey('src');
    expect($item)->toHaveKey('originalUrl');
    expect($item)->toHaveKey('thumbnail');
    expect($item)->toHaveKey('width');
    expect($item)->toHaveKey('height');
    expect($item)->toHaveKey('page');
    expect($item)->toHaveKey('key');
    expect($item)->toHaveKey('index');
    expect($item['key'])->toBe("{$item['page']}-{$item['id']}");
});

test('items use correct page number from params', function () {
    $user = User::factory()->create();
    $file = File::factory()->create(['referrer_url' => 'https://example.com/file.jpg']);

    $tab = Tab::factory()->for($user)
        ->withParams(['page' => 5])
        ->withFiles([$file->id])
        ->create();

    $response = $this->actingAs($user)->getJson(route('api.tabs.show', ['tab' => $tab->id]));

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['tab']['items'][0]['page'])->toBe(5);
});

test('items default to page 1 when params page is missing', function () {
    $user = User::factory()->create();
    $file = File::factory()->create(['referrer_url' => 'https://example.com/file.jpg']);

    $tab = Tab::factory()->for($user)
        ->withParams([])
        ->withFiles([$file->id])
        ->create();

    $response = $this->actingAs($user)->getJson(route('api.tabs.show', ['tab' => $tab->id]));

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['tab']['items'][0]['page'])->toBe(1);
});

test('tab without files returns empty items', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create();

    $response = $this->actingAs($user)->getJson(route('api.tabs.show', ['tab' => $tab->id]));

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['tab'])->toHaveKey('items');
    expect($data['tab']['items'])->toBeArray();
    expect($data['tab']['items'])->toBeEmpty();
});

test('user cannot load items for another users tab', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $file = File::factory()->create(['referrer_url' => 'https://example.com/file.jpg']);

    $tab = Tab::factory()->for($user1)->withFiles([$file->id])->create();

    $response = $this->actingAs($user2)->getJson("/api/tabs/{$tab->id}");

    $response->assertForbidden();
});

test('guest cannot load tab items', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create();

    $response = $this->getJson(route('api.tabs.show', ['tab' => $tab->id]));

    $response->assertUnauthorized();
});

test('items maintain file order based on pivot position', function () {
    $user = User::factory()->create();
    $file1 = File::factory()->create(['referrer_url' => 'https://example.com/file1.jpg']);
    $file2 = File::factory()->create(['referrer_url' => 'https://example.com/file2.jpg']);
    $file3 = File::factory()->create(['referrer_url' => 'https://example.com/file3.jpg']);

    // Create tab with files in specific order: file3, file1, file2
    $tab = Tab::factory()->for($user)->withFiles([$file3->id, $file1->id, $file2->id])->create();

    $response = $this->actingAs($user)->getJson(route('api.tabs.show', ['tab' => $tab->id]));

    $response->assertSuccessful();
    $data = $response->json();
    expect(count($data['tab']['items']))->toBe(3);
    // Verify order by checking id matches the order we specified
    expect($data['tab']['items'][0]['id'])->toBe($file3->id);
    expect($data['tab']['items'][1]['id'])->toBe($file1->id);
    expect($data['tab']['items'][2]['id'])->toBe($file2->id);
});
