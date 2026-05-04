<?php

use App\Models\Container;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('restore civitai containers refreshes metadata and attaches containers for one positive reacted file', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => '89366076',
        'url' => 'https://image.civitai.com/stale.jpg',
        'referrer_url' => null,
        'listing_metadata' => null,
    ]);

    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    Http::fake([
        'https://civitai.com/api/v1/images*' => Http::response([
            'items' => [
                [
                    'id' => 89366076,
                    'url' => 'https://image.civitai.com/test/original=true/89366076.jpeg',
                    'hash' => 'abc123',
                    'width' => 1024,
                    'height' => 1536,
                    'type' => 'image',
                    'nsfw' => false,
                    'postId' => 19824281,
                    'username' => 'fixture-user',
                    'meta' => [
                        'prompt' => 'test prompt',
                    ],
                ],
            ],
            'metadata' => [
                'nextCursor' => null,
            ],
        ]),
    ]);

    $this->artisan('atlas:restore-civitai-containers', [
        '--file-id' => $file->id,
        '--limit' => 1,
        '--delay-ms' => 0,
    ])->assertSuccessful();

    Http::assertSent(function ($request): bool {
        $parts = parse_url($request->url());
        parse_str($parts['query'] ?? '', $query);

        return ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === 'civitai.com'
            && ($parts['path'] ?? null) === '/api/v1/images'
            && ($query['imageId'] ?? null) === '89366076'
            && ($query['limit'] ?? null) === '1';
    });

    $file->refresh();

    expect($file->source)->toBe('CivitAI')
        ->and($file->source_id)->toBe('89366076')
        ->and($file->hash)->toBe('abc123')
        ->and($file->referrer_url)->toBe('https://civitai.com/images/89366076')
        ->and(data_get($file->listing_metadata, 'postId'))->toBe(19824281)
        ->and(data_get($file->listing_metadata, 'username'))->toBe('fixture-user');

    expect(FileMetadata::query()->where('file_id', $file->id)->exists())->toBeTrue();

    $post = Container::query()
        ->where('type', 'Post')
        ->where('source', 'CivitAI')
        ->where('source_id', '19824281')
        ->first();
    $userContainer = Container::query()
        ->where('type', 'User')
        ->where('source', 'CivitAI')
        ->where('source_id', 'fixture-user')
        ->first();

    expect($post)->not->toBeNull()
        ->and($userContainer)->not->toBeNull()
        ->and($file->containers()->where('containers.id', $post?->id)->exists())->toBeTrue()
        ->and($file->containers()->where('containers.id', $userContainer?->id)->exists())->toBeTrue();
});
