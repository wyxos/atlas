<?php

use App\Models\File;
use Tests\TestCase;
use Wyxos\Atlas\Plugin\Wallhaven\WallhavenService;

uses(TestCase::class);

it('appends api key to nsfw wallhaven urls', function () {
    config()->set('services.wallhaven.key', 'secret-key');

    $file = File::factory()->make([
        'source' => 'Wallhaven',
        'url' => 'https://w.wallhaven.cc/full/xx/wallhaven-xx1234.jpg',
        'listing_metadata' => ['purity' => 'nsfw'],
    ]);

    $service = new WallhavenService;

    $decorated = $service->decorateOriginalUrl($file, $file->url);

    expect($decorated)->toBe('https://w.wallhaven.cc/full/xx/wallhaven-xx1234.jpg?apikey=secret-key');
});

it('keeps original url when api key is missing or purity is not nsfw', function () {
    config()->set('services.wallhaven.key', null);

    $file = File::factory()->make([
        'source' => 'Wallhaven',
        'url' => 'https://w.wallhaven.cc/full/yy/wallhaven-yy4321.png',
        'listing_metadata' => ['purity' => 'sfw'],
    ]);

    $service = new WallhavenService;

    $decorated = $service->decorateOriginalUrl($file, $file->url);

    expect($decorated)->toBe('https://w.wallhaven.cc/full/yy/wallhaven-yy4321.png');
});

it('preserves existing query parameters when appending wallhaven api key', function () {
    config()->set('services.wallhaven.key', 'secret-key');

    $file = File::factory()->make([
        'source' => 'Wallhaven',
        'url' => 'https://w.wallhaven.cc/full/zz/wallhaven-zz5678.jpg?foo=bar',
        'listing_metadata' => ['purity' => 'nsfw'],
    ]);

    $service = new WallhavenService;

    $decorated = $service->decorateOriginalUrl($file, $file->url);

    expect($decorated)->toBe('https://w.wallhaven.cc/full/zz/wallhaven-zz5678.jpg?foo=bar&apikey=secret-key');
});
