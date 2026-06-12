<?php

use App\Jobs\SyncLibraryFiles;
use App\Models\Container;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('user can restore CivitAI listing metadata from the single image lookup', function () {
    Bus::fake([SyncLibraryFiles::class]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => '133523267',
        'url' => 'https://image.civitai.com/stale/original=true/133523267.jpeg',
        'referrer_url' => 'https://civitai.com/images/133523267',
        'filename' => 'existing-downloaded.jpg',
        'path' => 'private/images/existing-downloaded.jpg',
        'preview_path' => 'private/images/existing-preview.jpg',
        'downloaded' => true,
        'downloaded_at' => now()->subDay(),
        'listing_metadata' => [
            'id' => 133523267,
            'meta' => null,
            'local_only' => 'preserve this',
        ],
    ]);
    $file->metadata()->create(['payload' => ['local_only' => 'keep me']]);

    Http::fake([
        'https://civitai.com/api/v1/images*' => Http::response([
            'items' => [
                [
                    'id' => 133523267,
                    'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/fresh-guid/original=true/133523267.jpeg',
                    'hash' => 'UABC123fixture',
                    'width' => 1024,
                    'height' => 1536,
                    'type' => 'image',
                    'nsfw' => false,
                    'postId' => 29151655,
                    'username' => 'fixture-user',
                    'meta' => [
                        'prompt' => 'restored prompt from CivitAI',
                        'negativePrompt' => 'low quality',
                        'resources' => [
                            [
                                'name' => 'Fixture Model',
                                'type' => 'Checkpoint',
                                'modelVersionId' => 456,
                            ],
                        ],
                    ],
                ],
            ],
            'metadata' => [
                'nextCursor' => null,
            ],
        ]),
    ]);

    $response = $this->actingAs($user)->postJson("/api/files/{$file->id}/source-metadata/listing");

    $response->assertSuccessful()
        ->assertJsonPath('supported', true)
        ->assertJsonPath('status', 'restored')
        ->assertJsonPath('target', 'listing')
        ->assertJsonPath('file.id', $file->id)
        ->assertJsonPath('file.metadata.payload.local_only', 'keep me')
        ->assertJsonPath('file.listing_metadata.meta.prompt', 'restored prompt from CivitAI')
        ->assertJsonPath('file.listing_metadata.local_only', 'preserve this')
        ->assertJsonPath('file.detail_metadata', null)
        ->assertJsonPath('file.filename', 'existing-downloaded.jpg')
        ->assertJsonPath('file.path', 'private/images/existing-downloaded.jpg')
        ->assertJsonPath('file.downloaded', true);

    Http::assertSent(function (Request $request): bool {
        $parts = parse_url($request->url());
        parse_str($parts['query'] ?? '', $query);

        return $request->method() === 'GET'
            && ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === 'civitai.com'
            && ($parts['path'] ?? null) === '/api/v1/images'
            && ($query['imageId'] ?? null) === '133523267'
            && ($query['limit'] ?? null) === '1'
            && ($query['withMeta'] ?? null) === 'true'
            && ($query['flatMeta'] ?? null) === 'true';
    });

    $file->refresh()->load('metadata');

    expect($file->filename)->toBe('existing-downloaded.jpg')
        ->and($file->path)->toBe('private/images/existing-downloaded.jpg')
        ->and($file->preview_path)->toBe('private/images/existing-preview.jpg')
        ->and($file->downloaded)->toBeTrue()
        ->and($file->source_id)->toBe('133523267')
        ->and(data_get($file->listing_metadata, 'local_only'))->toBe('preserve this')
        ->and(data_get($file->listing_metadata, 'meta.prompt'))->toBe('restored prompt from CivitAI')
        ->and(data_get($file->listing_metadata, 'postId'))->toBe(29151655)
        ->and($file->detail_metadata)->toBeNull()
        ->and($file->metadata?->payload)->toBe(['local_only' => 'keep me']);

    expect(Container::query()
        ->where('type', 'Post')
        ->where('source', 'CivitAI')
        ->where('source_id', '29151655')
        ->exists())->toBeTrue();

    Bus::assertDispatched(SyncLibraryFiles::class);
});

test('user can restore CivitAI detail metadata without changing listing metadata', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => '133523267',
        'listing_metadata' => [
            'id' => 133523267,
            'meta' => [
                'prompt' => 'existing listing prompt',
            ],
            'local_only' => 'preserve this',
        ],
        'detail_metadata' => [
            'local_detail' => 'keep detail',
        ],
    ]);

    Http::fake([
        'https://civitai.com/api/v1/images*' => Http::response([
            'items' => [
                [
                    'id' => 133523267,
                    'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/fresh-guid/original=true/133523267.jpeg',
                    'hash' => 'UABC123fixture',
                    'width' => 1024,
                    'height' => 1536,
                    'type' => 'image',
                    'postId' => 29151655,
                    'username' => 'fixture-user',
                    'meta' => [
                        'prompt' => 'restored detail prompt from CivitAI',
                    ],
                ],
            ],
            'metadata' => [
                'nextCursor' => null,
            ],
        ]),
    ]);

    $response = $this->actingAs($user)->postJson("/api/files/{$file->id}/source-metadata/detail");

    $response->assertSuccessful()
        ->assertJsonPath('supported', true)
        ->assertJsonPath('status', 'restored')
        ->assertJsonPath('target', 'detail')
        ->assertJsonPath('file.listing_metadata.meta.prompt', 'existing listing prompt')
        ->assertJsonPath('file.listing_metadata.local_only', 'preserve this')
        ->assertJsonPath('file.detail_metadata.local_detail', 'keep detail')
        ->assertJsonPath('file.detail_metadata.meta.prompt', 'restored detail prompt from CivitAI')
        ->assertJsonPath('file.detail_metadata.postId', 29151655);

    $file->refresh();

    expect(data_get($file->listing_metadata, 'meta.prompt'))->toBe('existing listing prompt')
        ->and(data_get($file->detail_metadata, 'meta.prompt'))->toBe('restored detail prompt from CivitAI')
        ->and(data_get($file->detail_metadata, 'local_detail'))->toBe('keep detail');
});

test('source metadata refresh rejects local files without calling CivitAI', function () {
    Http::fake();

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'source_id' => '133523267',
    ]);

    $response = $this->actingAs($user)->postJson("/api/files/{$file->id}/source-metadata/detail");

    $response->assertUnprocessable()
        ->assertJsonPath('supported', false)
        ->assertJsonPath('status', 'unsupported_source');

    Http::assertNothingSent();
});

test('source metadata restore stores partial CivitAI detail metadata when prompt is absent', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => '133523267',
        'listing_metadata' => [
            'id' => 133523267,
            'meta' => null,
            'local_only' => 'preserve this',
        ],
    ]);
    Http::fake([
        'https://civitai.com/api/v1/images*' => Http::response([
            'items' => [
                [
                    'id' => 133523267,
                    'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/fresh-guid/original=true/133523267.jpeg',
                    'hash' => 'UABC123fixture',
                    'width' => 1024,
                    'height' => 1536,
                    'type' => 'image',
                    'postId' => 29151655,
                    'username' => 'fixture-user',
                    'meta' => [],
                ],
            ],
            'metadata' => [
                'nextCursor' => null,
            ],
        ]),
    ]);

    $response = $this->actingAs($user)->postJson("/api/files/{$file->id}/source-metadata/detail");

    $response->assertSuccessful()
        ->assertJsonPath('supported', true)
        ->assertJsonPath('status', 'restored')
        ->assertJsonPath('target', 'detail')
        ->assertJsonPath('file.detail_metadata.width', 1024)
        ->assertJsonPath('file.detail_metadata.height', 1536)
        ->assertJsonPath('file.detail_metadata.postId', 29151655)
        ->assertJsonPath('file.listing_metadata.local_only', 'preserve this')
        ->assertJsonPath('file.listing_metadata.postId', null);

    $file->refresh()->load('metadata');

    expect(FileMetadata::query()->where('file_id', $file->id)->exists())->toBeFalse()
        ->and(data_get($file->listing_metadata, 'local_only'))->toBe('preserve this')
        ->and(data_get($file->listing_metadata, 'postId'))->toBeNull()
        ->and(data_get($file->detail_metadata, 'postId'))->toBe(29151655);
});

test('file resource exposes source metadata restore capabilities per source', function () {
    $user = User::factory()->create();
    $civitAiFile = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => '133523267',
    ]);
    $localFile = File::factory()->create([
        'source' => 'Local',
        'source_id' => '133523267',
    ]);

    $this->actingAs($user)->getJson("/api/files/{$civitAiFile->id}")
        ->assertSuccessful()
        ->assertJsonPath('file.capabilities.restore_listing_metadata', true)
        ->assertJsonPath('file.capabilities.restore_detail_metadata', true);

    $this->actingAs($user)->getJson("/api/files/{$localFile->id}")
        ->assertSuccessful()
        ->assertJsonPath('file.capabilities.restore_listing_metadata', false)
        ->assertJsonPath('file.capabilities.restore_detail_metadata', false);
});
