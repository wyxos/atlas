<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

it('resolves civitai media via browse endpoint', function () {
    Http::fake([
        'https://civitai.com/images/2048' => Http::response('<html><body><h1>404</h1></body></html>', 200),
        'https://image.civitai.com/thumbs/2048.jpeg' => Http::response('', 200, [
            'Content-Type' => 'video/mp4',
            'Content-Length' => '4096',
        ]),
    ]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'CivitAI',
        'thumbnail_url' => 'https://image.civitai.com/thumbs/2048.jpeg',
        'url' => 'https://image.civitai.com/posters/2048.mp4',
        'mime_type' => 'image/webp',
        'referrer_url' => 'https://civitai.com/images/2048',
        'listing_metadata' => [
            'url' => 'https://image.civitai.com/posters/2048.mp4',
        ],
    ]);

    $response = actingAs($user)->postJson(route('browse.files.resolve-media', $file->id));

    $response->assertOk()
        ->assertJson([
            'id' => $file->id,
            'resolved' => true,
            'not_found' => false,
            'mime_type' => 'video/mp4',
            'original' => 'https://image.civitai.com/thumbs/2048.jpeg',
            'type' => 'video',
        ]);

    $file->refresh();

    expect($file->url)->toBe('https://image.civitai.com/thumbs/2048.jpeg')
        ->and($file->mime_type)->toBe('video/mp4')
        ->and($file->not_found)->toBeFalse();
});
