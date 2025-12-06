<?php

use App\Models\BrowseTab;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

// Unit tests that don't need database should not use RefreshDatabase
// But these tests do need database, so we'll keep it

it('formats files to items structure correctly', function () {
    $file = File::factory()->create([
        'referrer_url' => 'https://civitai.com/images/123',
        'thumbnail_url' => 'https://civitai.com/images/123/thumb.jpg',
        'url' => 'https://civitai.com/images/123/original.jpg',
        'mime_type' => 'image/jpeg',
        'source_id' => 'source-123',
        'listing_metadata' => ['id' => '123'],
    ]);

    FileMetadata::factory()->for($file)->create([
        'payload' => ['width' => 1024, 'height' => 768],
    ]);

    $files = collect([$file]);
    $items = BrowseTab::formatFilesToItems($files, 5);

    expect($items)->toHaveCount(1);
    expect($items[0])->toHaveKeys(['id', 'width', 'height', 'src', 'originalUrl', 'thumbnail', 'type', 'page', 'index', 'notFound']);
    expect($items[0]['id'])->toBe('123');
    expect($items[0]['width'])->toBe(1024);
    expect($items[0]['height'])->toBe(768);
    expect($items[0]['src'])->toBe('https://civitai.com/images/123/thumb.jpg');
    expect($items[0]['originalUrl'])->toBe('https://civitai.com/images/123/original.jpg');
    expect($items[0]['thumbnail'])->toBe('https://civitai.com/images/123/thumb.jpg');
    expect($items[0]['type'])->toBe('image');
    expect($items[0]['page'])->toBe(5);
    expect($items[0]['index'])->toBe(0);
    expect($items[0]['notFound'])->toBeFalse();
});

it('uses source_id as fallback when listing_metadata id is missing', function () {
    $file = File::factory()->create([
        'referrer_url' => 'https://civitai.com/images/123',
        'source_id' => 'fallback-id',
        'listing_metadata' => null, // No listing_metadata
    ]);

    FileMetadata::factory()->for($file)->create([
        'payload' => ['width' => 500, 'height' => 500],
    ]);

    $files = collect([$file]);
    $items = BrowseTab::formatFilesToItems($files, 1);

    expect($items[0]['id'])->toBe('fallback-id');
});

it('uses file id as final fallback when source_id and listing_metadata are missing', function () {
    $file = File::factory()->create([
        'referrer_url' => 'https://civitai.com/images/123',
        'source_id' => null,
        'listing_metadata' => null,
    ]);

    FileMetadata::factory()->for($file)->create([
        'payload' => ['width' => 500, 'height' => 500],
    ]);

    $files = collect([$file]);
    $items = BrowseTab::formatFilesToItems($files, 1);

    expect($items[0]['id'])->toBe((string) $file->id);
});

it('defaults width and height to 500 when metadata is missing', function () {
    $file = File::factory()->create([
        'referrer_url' => 'https://civitai.com/images/123',
        'listing_metadata' => ['id' => '123'],
    ]);

    // No metadata created

    $files = collect([$file]);
    $items = BrowseTab::formatFilesToItems($files, 1);

    expect($items[0]['width'])->toBe(500);
    expect($items[0]['height'])->toBe(500);
});

it('uses url as src fallback when thumbnail_url is missing', function () {
    $file = File::factory()->create([
        'referrer_url' => 'https://civitai.com/images/123',
        'thumbnail_url' => null,
        'url' => 'https://civitai.com/images/123/original.jpg',
        'listing_metadata' => ['id' => '123'],
    ]);

    FileMetadata::factory()->for($file)->create([
        'payload' => ['width' => 500, 'height' => 500],
    ]);

    $files = collect([$file]);
    $items = BrowseTab::formatFilesToItems($files, 1);

    expect($items[0]['src'])->toBe('https://civitai.com/images/123/original.jpg');
    expect($items[0]['originalUrl'])->toBe('https://civitai.com/images/123/original.jpg');
});

it('determines type as video when mime_type starts with video/', function () {
    $videoFile = File::factory()->create([
        'referrer_url' => 'https://civitai.com/images/123',
        'mime_type' => 'video/mp4',
        'listing_metadata' => ['id' => '123'],
    ]);

    FileMetadata::factory()->for($videoFile)->create([
        'payload' => ['width' => 1920, 'height' => 1080],
    ]);

    $files = collect([$videoFile]);
    $items = BrowseTab::formatFilesToItems($files, 1);

    expect($items[0]['type'])->toBe('video');
});

it('determines type as image when mime_type does not start with video/', function () {
    $imageFile = File::factory()->create([
        'referrer_url' => 'https://civitai.com/images/123',
        'mime_type' => 'image/jpeg',
        'listing_metadata' => ['id' => '123'],
    ]);

    FileMetadata::factory()->for($imageFile)->create([
        'payload' => ['width' => 1024, 'height' => 768],
    ]);

    $files = collect([$imageFile]);
    $items = BrowseTab::formatFilesToItems($files, 1);

    expect($items[0]['type'])->toBe('image');
});

it('sets correct index for each item', function () {
    $files = File::factory()->count(3)->create();
    $files->each(function ($file, $index) {
        $file->listing_metadata = ['id' => (string) ($index + 1)];
        $file->save();
    });

    $files->each(function ($file) {
        FileMetadata::factory()->for($file)->create([
            'payload' => ['width' => 500, 'height' => 500],
        ]);
    });

    $items = BrowseTab::formatFilesToItems($files, 1);

    expect($items)->toHaveCount(3);
    expect($items[0]['index'])->toBe(0);
    expect($items[1]['index'])->toBe(1);
    expect($items[2]['index'])->toBe(2);
});

it('uses provided page parameter', function () {
    $file = File::factory()->create([
        'listing_metadata' => ['id' => '123'],
    ]);

    FileMetadata::factory()->for($file)->create([
        'payload' => ['width' => 500, 'height' => 500],
    ]);

    $files = collect([$file]);
    $items = BrowseTab::formatFilesToItems($files, 42);

    expect($items[0]['page'])->toBe(42);
});

it('defaults to page 1 when page parameter is not provided', function () {
    $file = File::factory()->create([
        'listing_metadata' => ['id' => '123'],
    ]);

    FileMetadata::factory()->for($file)->create([
        'payload' => ['width' => 500, 'height' => 500],
    ]);

    $files = collect([$file]);
    $items = BrowseTab::formatFilesToItems($files);

    expect($items[0]['page'])->toBe(1);
});

it('handles empty file collection', function () {
    $files = collect([]);
    $items = BrowseTab::formatFilesToItems($files, 1);

    expect($items)->toBe([]);
});

it('belongs to a user', function () {
    $user = User::factory()->create();
    $tab = BrowseTab::factory()->for($user)->create();

    expect($tab->user)->toBeInstanceOf(User::class);
    expect($tab->user->id)->toBe($user->id);
});

it('scopes tabs for a specific user', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    BrowseTab::factory()->for($user1)->count(2)->create();
    BrowseTab::factory()->for($user2)->count(3)->create();

    $user1Tabs = BrowseTab::forUser($user1->id)->get();
    expect($user1Tabs)->toHaveCount(2);

    $user2Tabs = BrowseTab::forUser($user2->id)->get();
    expect($user2Tabs)->toHaveCount(3);
});

it('orders tabs by position', function () {
    $user = User::factory()->create();

    $tab3 = BrowseTab::factory()->for($user)->create(['position' => 2]);
    $tab1 = BrowseTab::factory()->for($user)->create(['position' => 0]);
    $tab2 = BrowseTab::factory()->for($user)->create(['position' => 1]);

    $orderedTabs = BrowseTab::ordered()->get();

    expect($orderedTabs[0]->id)->toBe($tab1->id);
    expect($orderedTabs[1]->id)->toBe($tab2->id);
    expect($orderedTabs[2]->id)->toBe($tab3->id);
});

it('casts query_params to array', function () {
    $user = User::factory()->create();
    $tab = BrowseTab::factory()->for($user)->create([
        'query_params' => ['page' => 5, 'next' => '10|1234567890'],
    ]);

    expect($tab->query_params)->toBeArray();
    expect($tab->query_params['page'])->toBe(5);
    expect($tab->query_params['next'])->toBe('10|1234567890');
});

it('casts file_ids to array', function () {
    $user = User::factory()->create();
    $tab = BrowseTab::factory()->for($user)->create([
        'file_ids' => ['https://civitai.com/images/123', 'https://civitai.com/images/456'],
    ]);

    expect($tab->file_ids)->toBeArray();
    expect($tab->file_ids)->toHaveCount(2);
});

it('casts position to integer', function () {
    $user = User::factory()->create();
    $tab = BrowseTab::factory()->for($user)->create(['position' => '5']);

    expect($tab->position)->toBeInt();
    expect($tab->position)->toBe(5);
});
