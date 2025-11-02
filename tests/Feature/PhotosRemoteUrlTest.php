<?php

use App\Models\File;
use App\Models\User;

it('decorates remote wallhaven originals in photos payload', function () {
    useTypesense();

    config()->set('services.wallhaven.key', 'secret-key');

    $user = User::factory()->create();
    $this->actingAs($user);

    $file = File::factory()->create([
        'source' => 'Wallhaven',
        'source_id' => 'xx1234',
        'url' => 'https://w.wallhaven.cc/full/xx/wallhaven-xx1234.jpg',
        'thumbnail_url' => 'https://th.wallhaven.cc/orig/xx/wallhaven-xx1234.jpg',
        'listing_metadata' => ['purity' => 'nsfw'],
        'path' => null,
        'downloaded' => false,
        'mime_type' => 'image/jpeg',
    ]);

    File::query()->searchable();

    $response = $this->getJson(route('photos.data'));
    $response->assertOk();

    $payload = $response->json();
    $decorated = collect($payload['files'] ?? [])->firstWhere('id', $file->id);

    expect($decorated)->not->toBeNull()
        ->and($decorated['original'] ?? null)->toBe('https://w.wallhaven.cc/full/xx/wallhaven-xx1234.jpg?apikey=secret-key')
        ->and($decorated['preview'] ?? null)->toBe('https://th.wallhaven.cc/orig/xx/wallhaven-xx1234.jpg');
});
