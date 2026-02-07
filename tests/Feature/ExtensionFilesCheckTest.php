<?php

use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('checks whether external files exist by original url', function () {
    config()->set('downloads.extension_token', 'test-token');

    File::factory()->create([
        'referrer_url' => 'https://example.com/media/one.jpg',
        'downloaded' => true,
    ]);

    $response = $this
        ->withHeader('X-Atlas-Extension-Token', 'test-token')
        ->postJson('/api/extension/files/check', [
            'urls' => [
                'https://example.com/media/one.jpg',
                'https://example.com/media/two.jpg',
            ],
        ]);

    $response->assertOk();
    $response->assertJson([
        'results' => [
            [
                'url' => 'https://example.com/media/one.jpg',
                'exists' => true,
                'downloaded' => true,
            ],
            [
                'url' => 'https://example.com/media/two.jpg',
                'exists' => false,
                'downloaded' => false,
                'file_id' => null,
            ],
        ],
    ]);
});
