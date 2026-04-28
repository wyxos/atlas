<?php

use App\Jobs\DownloadFile;
use App\Models\Container;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/ExtensionReactionsTestSupport.php';

uses(RefreshDatabase::class);

test('single civitai image reactions persist civitai source and derived post user and resource containers', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);
    $canonicalUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/cf304569-f64a-4f90-aa1b-16bfb641f143/original=true/cf304569-f64a-4f90-aa1b-16bfb641f143.jpeg';

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions', [
        'type' => 'like',
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/cf304569-f64a-4f90-aa1b-16bfb641f143/original=true,quality=90/1341e338-9de2-4d1a-a6ff-6c86fb7ad759.jpeg',
        'referrer_url_hash_aware' => 'https://civitai.com/images/9101004',
        'page_url' => 'https://civitai.com/images/9101004',
        'tag_name' => 'img',
        'listing_metadata_overrides' => [
            'postId' => 9202001,
            'username' => 'exampleCreator',
            'resource_containers' => [
                [
                    'type' => 'Checkpoint',
                    'modelId' => 9303001,
                    'modelVersionId' => 9404001,
                    'referrerUrl' => 'https://civitai.com/models/9303001/example-checkpoint?modelVersionId=9404001',
                ],
                [
                    'type' => 'LoRA',
                    'modelId' => 9303002,
                    'modelVersionId' => 9404002,
                    'referrerUrl' => 'https://civitai.com/models/9303002/example-lora?modelVersionId=9404002',
                ],
            ],
        ],
    ]);

    $response->assertSuccessful();

    $response->assertJsonPath('file.url', $canonicalUrl);

    $file = File::query()->where('url', $canonicalUrl)->first();

    expect($file)->not->toBeNull();
    expect($file?->source)->toBe('CivitAI');
    expect($file?->source_id)->toBe('9101004');
    expect($file?->referrer_url)->toBe('https://civitai.com/images/9101004');
    expect(data_get($file?->listing_metadata, 'postId'))->toBe(9202001);
    expect(data_get($file?->listing_metadata, 'username'))->toBe('exampleCreator');
    expect(data_get($file?->listing_metadata, 'resource_containers.0.modelVersionId'))->toBe(9404001);
    expect(data_get($file?->listing_metadata, 'resource_containers.1.modelVersionId'))->toBe(9404002);

    $this->assertDatabaseHas('containers', [
        'type' => 'Post',
        'source' => 'CivitAI',
        'source_id' => '9202001',
        'referrer' => 'https://civitai.com/posts/9202001',
    ]);
    $this->assertDatabaseHas('containers', [
        'type' => 'User',
        'source' => 'CivitAI',
        'source_id' => 'exampleCreator',
        'referrer' => 'https://civitai.com/user/exampleCreator',
    ]);
    $this->assertDatabaseHas('containers', [
        'type' => 'Checkpoint',
        'source' => 'CivitAI',
        'source_id' => '9404001',
        'referrer' => 'https://civitai.com/models/9303001/example-checkpoint?modelVersionId=9404001',
    ]);
    $this->assertDatabaseHas('containers', [
        'type' => 'LoRA',
        'source' => 'CivitAI',
        'source_id' => '9404002',
        'referrer' => 'https://civitai.com/models/9303002/example-lora?modelVersionId=9404002',
    ]);

    expect($file?->containers()->where('type', 'Post')->exists())->toBeTrue();
    expect($file?->containers()->where('type', 'User')->exists())->toBeTrue();
    expect($file?->containers()->where('type', 'Checkpoint')->exists())->toBeTrue();
    expect($file?->containers()->where('type', 'LoRA')->exists())->toBeTrue();
});

test('extension reactions reuse the existing civitai browse row for the same image page', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $canonicalUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/51215cf6-0dd6-4643-9e7d-05e8c9f0849a/original=true/51215cf6-0dd6-4643-9e7d-05e8c9f0849a.jpeg';
    $existing = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => '9101005',
        'url' => $canonicalUrl,
        'referrer_url' => 'https://civitai.com/images/9101005',
        'preview_url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/51215cf6-0dd6-4643-9e7d-05e8c9f0849a/anim=false,width=450,optimized=true/9101005.jpeg',
        'filename' => 'existing-civitai',
        'ext' => 'jpeg',
    ]);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions', [
        'type' => 'like',
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/51215cf6-0dd6-4643-9e7d-05e8c9f0849a/original=true,quality=90/2026-03-03-145847_1056106972632616_Upscale.jpeg',
        'referrer_url_hash_aware' => 'https://civitai.com/images/9101005',
        'page_url' => 'https://civitai.com/images/9101005',
        'tag_name' => 'img',
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('file.id', $existing->id);
    $response->assertJsonPath('file.url', $canonicalUrl);

    expect(File::query()->count())->toBe(1);
    expect(Reaction::query()->where('user_id', $user->id)->where('file_id', $existing->id)->value('type'))->toBe('like');
});

test('extension reactions reuse existing civitai rows when submitted from civitai red', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $canonicalUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/8928e082-af52-4ade-a86e-d79e0ed63aa9/original=true/8928e082-af52-4ade-a86e-d79e0ed63aa9.jpeg';
    $existing = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => '9101001',
        'url' => $canonicalUrl,
        'referrer_url' => 'https://civitai.com/images/9101001',
        'preview_url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/8928e082-af52-4ade-a86e-d79e0ed63aa9/anim=false,width=450,optimized=true/9101001.jpeg',
        'filename' => 'existing-civitai-red',
        'ext' => 'jpeg',
    ]);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions', [
        'type' => 'love',
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/8928e082-af52-4ade-a86e-d79e0ed63aa9/original=true,quality=90/f3a666a2-65dd-4738-a1f2-dd1de72f2636.jpeg',
        'referrer_url_hash_aware' => 'https://civitai.red/images/9101001',
        'page_url' => 'https://civitai.red/images/9101001',
        'tag_name' => 'img',
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('file.id', $existing->id);
    $response->assertJsonPath('file.url', $canonicalUrl);
    $response->assertJsonPath('file.referrer_url', 'https://civitai.red/images/9101001');

    expect(File::query()->count())->toBe(1);
    expect(Reaction::query()->where('user_id', $user->id)->where('file_id', $existing->id)->value('type'))->toBe('love');
});

test('extension batch reactions queue all submitted gallery items and return the selected primary item', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions/batch', [
        'type' => 'like',
        'primary_candidate_id' => 'image-2',
        'items' => [
            [
                'candidate_id' => 'image-1',
                'url' => 'https://images.example.test/direct-image-1.jpg',
                'referrer_url_hash_aware' => 'https://www.deviantart.com/artist/art/post-1',
                'page_url' => 'https://www.deviantart.com/artist/art/post-1',
                'tag_name' => 'img',
            ],
            [
                'candidate_id' => 'image-2',
                'url' => 'https://images.example.test/direct-image-2.jpg',
                'referrer_url_hash_aware' => 'https://www.deviantart.com/artist/art/post-1#image-2',
                'page_url' => 'https://www.deviantart.com/artist/art/post-1',
                'tag_name' => 'img',
            ],
        ],
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('file.url', 'https://images.example.test/direct-image-2.jpg');
    $response->assertJsonPath('file.referrer_url', 'https://www.deviantart.com/artist/art/post-1#image-2');
    $response->assertJsonPath('batch.count', 2);
    $response->assertJsonPath('batch.primary_candidate_id', 'image-2');
    $response->assertJsonPath('batch.download_requested', true);

    $firstFile = File::query()->where('url', 'https://images.example.test/direct-image-1.jpg')->first();
    $secondFile = File::query()->where('url', 'https://images.example.test/direct-image-2.jpg')->first();

    expect($firstFile)->not->toBeNull();
    expect($secondFile)->not->toBeNull();
    expect($firstFile?->source)->toBe('deviantart.com');
    expect($secondFile?->source)->toBe('deviantart.com');
    expect($firstFile?->referrer_url)->toBe('https://www.deviantart.com/artist/art/post-1');
    expect($secondFile?->referrer_url)->toBe('https://www.deviantart.com/artist/art/post-1#image-2');
    expect(data_get($firstFile?->listing_metadata, 'post_container_referrer_url'))
        ->toBe('https://www.deviantart.com/artist/art/post-1');
    expect(data_get($secondFile?->listing_metadata, 'post_container_referrer_url'))
        ->toBe('https://www.deviantart.com/artist/art/post-1');
    expect(data_get($firstFile?->listing_metadata, 'post_container_source'))
        ->toBe('deviantart.com');
    expect(data_get($secondFile?->listing_metadata, 'post_container_source'))
        ->toBe('deviantart.com');
    expect(data_get($firstFile?->listing_metadata, 'user_container_source'))
        ->toBe('deviantart.com');
    expect(data_get($secondFile?->listing_metadata, 'user_container_source'))
        ->toBe('deviantart.com');
    expect(data_get($firstFile?->listing_metadata, 'user_container_source_id'))
        ->toBe('artist');
    expect(data_get($secondFile?->listing_metadata, 'user_container_source_id'))
        ->toBe('artist');
    expect(data_get($firstFile?->listing_metadata, 'user_container_referrer_url'))
        ->toBe('https://www.deviantart.com/artist/gallery');
    expect(data_get($secondFile?->listing_metadata, 'user_container_referrer_url'))
        ->toBe('https://www.deviantart.com/artist/gallery');

    expect(Reaction::query()
        ->where('user_id', $user->id)
        ->where('file_id', $firstFile?->id)
        ->value('type'))->toBe('like');
    expect(Reaction::query()
        ->where('user_id', $user->id)
        ->where('file_id', $secondFile?->id)
        ->value('type'))->toBe('like');

    $this->assertDatabaseHas('containers', [
        'type' => 'Post',
        'source' => 'deviantart.com',
        'source_id' => 'https://www.deviantart.com/artist/art/post-1',
        'referrer' => 'https://www.deviantart.com/artist/art/post-1',
    ]);

    $container = Container::query()
        ->where('type', 'Post')
        ->where('source', 'deviantart.com')
        ->where('source_id', 'https://www.deviantart.com/artist/art/post-1')
        ->first();

    expect($container)->not->toBeNull();
    expect($firstFile?->containers()->where('containers.id', $container?->id)->exists())->toBeTrue();
    expect($secondFile?->containers()->where('containers.id', $container?->id)->exists())->toBeTrue();

    $this->assertDatabaseHas('containers', [
        'type' => 'User',
        'source' => 'deviantart.com',
        'source_id' => 'artist',
        'referrer' => 'https://www.deviantart.com/artist/gallery',
    ]);

    $userContainer = Container::query()
        ->where('type', 'User')
        ->where('source', 'deviantart.com')
        ->where('source_id', 'artist')
        ->first();

    expect($userContainer)->not->toBeNull();
    expect($firstFile?->containers()->where('containers.id', $userContainer?->id)->exists())->toBeTrue();
    expect($secondFile?->containers()->where('containers.id', $userContainer?->id)->exists())->toBeTrue();

    Queue::assertPushed(DownloadFile::class, 2);
});

test('extension batch reactions create a user container for deviantart gallery urls', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $galleryUrl = 'https://www.deviantart.com/aipayop/gallery';

    $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions/batch', [
        'type' => 'like',
        'primary_candidate_id' => 'image-1',
        'items' => [
            [
                'candidate_id' => 'image-1',
                'url' => 'https://images.example.test/gallery-image-1.jpg',
                'referrer_url_hash_aware' => $galleryUrl,
                'page_url' => $galleryUrl,
                'tag_name' => 'img',
            ],
            [
                'candidate_id' => 'image-2',
                'url' => 'https://images.example.test/gallery-image-2.jpg',
                'referrer_url_hash_aware' => $galleryUrl,
                'page_url' => $galleryUrl,
                'tag_name' => 'img',
            ],
        ],
    ])->assertSuccessful();

    $this->assertDatabaseHas('containers', [
        'type' => 'Post',
        'source' => 'deviantart.com',
        'source_id' => $galleryUrl,
        'referrer' => $galleryUrl,
    ]);

    $this->assertDatabaseHas('containers', [
        'type' => 'User',
        'source' => 'deviantart.com',
        'source_id' => 'aipayop',
        'referrer' => 'https://www.deviantart.com/aipayop/gallery',
    ]);
});

test('extension batch reactions persist civitai shared containers with per-item image referrers', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);
    $canonicalVideoUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/0d6b721b-6256-4849-99ba-05c7cbb5b64f/transcode=true,original=true,quality=90/9101002.mp4';
    $canonicalImageUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/cf304569-f64a-4f90-aa1b-16bfb641f143/original=true/cf304569-f64a-4f90-aa1b-16bfb641f143.jpeg';

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions/batch', [
        'type' => 'like',
        'primary_candidate_id' => 'image-9101004',
        'listing_metadata_overrides' => [
            'postId' => 9202002,
            'username' => 'exampleArtist',
            'resource_containers' => [
                [
                    'type' => 'Checkpoint',
                    'modelId' => 9303003,
                    'modelVersionId' => 9404003,
                    'referrerUrl' => 'https://civitai.com/models/9303003/example-shared-checkpoint?modelVersionId=9404003',
                ],
                [
                    'type' => 'LoRA',
                    'modelId' => 9303004,
                    'modelVersionId' => 9404004,
                    'referrerUrl' => 'https://civitai.com/models/9303004/example-shared-lora?modelVersionId=9404004',
                ],
            ],
        ],
        'items' => [
            [
                'candidate_id' => 'image-9101002',
                'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/0d6b721b-6256-4849-99ba-05c7cbb5b64f/transcode=true,original=true,quality=90/4MGM4VAVYH67B8TYY43NVH1780.mp4',
                'referrer_url_hash_aware' => 'https://civitai.com/images/9101002',
                'page_url' => 'https://civitai.com/posts/9202002',
                'tag_name' => 'video',
            ],
            [
                'candidate_id' => 'image-9101004',
                'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/cf304569-f64a-4f90-aa1b-16bfb641f143/original=true,quality=90/1341e338-9de2-4d1a-a6ff-6c86fb7ad759.jpeg',
                'referrer_url_hash_aware' => 'https://civitai.com/images/9101004',
                'page_url' => 'https://civitai.com/posts/9202002',
                'tag_name' => 'img',
            ],
        ],
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('file.url', $canonicalImageUrl);
    $response->assertJsonPath('file.referrer_url', 'https://civitai.com/images/9101004');

    $videoFile = File::query()->where('url', $canonicalVideoUrl)->first();
    $imageFile = File::query()->where('url', $canonicalImageUrl)->first();

    expect($videoFile)->not->toBeNull();
    expect($imageFile)->not->toBeNull();
    expect($videoFile?->source)->toBe('CivitAI');
    expect($imageFile?->source)->toBe('CivitAI');
    expect($videoFile?->source_id)->toBe('9101002');
    expect($imageFile?->source_id)->toBe('9101004');
    expect($videoFile?->referrer_url)->toBe('https://civitai.com/images/9101002');
    expect($imageFile?->referrer_url)->toBe('https://civitai.com/images/9101004');
    expect(data_get($videoFile?->listing_metadata, 'postId'))->toBe(9202002);
    expect(data_get($imageFile?->listing_metadata, 'postId'))->toBe(9202002);
    expect(data_get($videoFile?->listing_metadata, 'resource_containers.0.modelVersionId'))->toBe(9404003);
    expect(data_get($imageFile?->listing_metadata, 'resource_containers.1.modelVersionId'))->toBe(9404004);

    $this->assertDatabaseHas('containers', [
        'type' => 'Post',
        'source' => 'CivitAI',
        'source_id' => '9202002',
        'referrer' => 'https://civitai.com/posts/9202002',
    ]);
    $this->assertDatabaseHas('containers', [
        'type' => 'User',
        'source' => 'CivitAI',
        'source_id' => 'exampleArtist',
        'referrer' => 'https://civitai.com/user/exampleArtist',
    ]);
    $this->assertDatabaseHas('containers', [
        'type' => 'Checkpoint',
        'source' => 'CivitAI',
        'source_id' => '9404003',
        'referrer' => 'https://civitai.com/models/9303003/example-shared-checkpoint?modelVersionId=9404003',
    ]);
    $this->assertDatabaseHas('containers', [
        'type' => 'LoRA',
        'source' => 'CivitAI',
        'source_id' => '9404004',
        'referrer' => 'https://civitai.com/models/9303004/example-shared-lora?modelVersionId=9404004',
    ]);

    expect($videoFile?->containers()->where('type', 'Post')->exists())->toBeTrue();
    expect($videoFile?->containers()->where('type', 'User')->exists())->toBeTrue();
    expect($videoFile?->containers()->where('type', 'Checkpoint')->exists())->toBeTrue();
    expect($videoFile?->containers()->where('type', 'LoRA')->exists())->toBeTrue();
    expect($imageFile?->containers()->where('type', 'Post')->exists())->toBeTrue();
    expect($imageFile?->containers()->where('type', 'User')->exists())->toBeTrue();
    expect($imageFile?->containers()->where('type', 'Checkpoint')->exists())->toBeTrue();
    expect($imageFile?->containers()->where('type', 'LoRA')->exists())->toBeTrue();
});

test('extension batch reactions do not create post or user containers for non-deviantart urls', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $postUrl = 'https://www.example.test/post/123';

    $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions/batch', [
        'type' => 'like',
        'primary_candidate_id' => 'image-1',
        'items' => [
            [
                'candidate_id' => 'image-1',
                'url' => 'https://images.example.test/non-da-image-1.jpg',
                'referrer_url_hash_aware' => $postUrl,
                'page_url' => $postUrl,
                'tag_name' => 'img',
            ],
            [
                'candidate_id' => 'image-2',
                'url' => 'https://images.example.test/non-da-image-2.jpg',
                'referrer_url_hash_aware' => $postUrl,
                'page_url' => $postUrl,
                'tag_name' => 'img',
            ],
        ],
    ])->assertSuccessful();

    $this->assertDatabaseCount('containers', 0);
});
