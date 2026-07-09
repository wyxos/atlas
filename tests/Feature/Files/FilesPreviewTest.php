<?php

use App\Events\FilePreviewAssetsUpdated;
use App\Jobs\DownloadFile;
use App\Jobs\GenerateFilePreviewAssets;
use App\Models\File;
use App\Models\MediaProcessorTask;
use App\Models\User;
use App\Services\LibraryScans\MediaProbeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('preview request queues generation when preview is missing', function () {
    /** @var \Tests\TestCase $this */
    /** @var \App\Models\User $admin */
    $admin = User::factory()->admin()->create();

    Queue::fake();
    Storage::fake(config('downloads.disk'));

    $file = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/aa/bb/test.mp4',
        'preview_path' => null,
        'mime_type' => 'video/mp4',
        'size' => 5,
    ]);

    Storage::disk(config('downloads.disk'))->put($file->path, 'video');

    $response = $this->actingAs($admin)->get("/api/files/{$file->id}/preview");

    $response->assertNotFound();
    Queue::assertPushed(GenerateFilePreviewAssets::class);
});

test('authenticated users can queue preview asset regeneration for downloaded files', function () {
    /** @var \Tests\TestCase $this */
    /** @var \App\Models\User $admin */
    $admin = User::factory()->admin()->create();

    Queue::fake();
    Storage::fake(config('downloads.disk'));
    fakePreviewAssetsVideoProbe();

    $file = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/aa/bb/test.mp4',
        'preview_path' => null,
        'poster_path' => null,
        'mime_type' => 'video/mp4',
        'size' => 5,
    ]);

    Storage::disk(config('downloads.disk'))->put($file->path, 'video');

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/preview-assets");

    $response->assertAccepted();
    $response->assertJsonPath('queued', true);
    $response->assertJsonPath('action', 'preview_queued');
    $response->assertJsonPath('file.id', $file->id);
    $response->assertJsonPath('file.preview_generation.status', 'queued');
    Queue::assertPushed(GenerateFilePreviewAssets::class, fn (GenerateFilePreviewAssets $job): bool => $job->fileId === $file->id && $job->force === true);
});

test('missing disk originals with live remote sources queue a safe redownload', function () {
    /** @var \Tests\TestCase $this */
    /** @var \App\Models\User $admin */
    $admin = User::factory()->admin()->create();

    Queue::fake();
    Event::fake([FilePreviewAssetsUpdated::class]);
    Storage::fake(config('downloads.disk'));

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/example/original.jpeg',
        'referrer_url' => 'https://civitai.com/images/123',
        'downloaded' => true,
        'downloaded_at' => now(),
        'path' => 'downloads/aa/bb/missing.jpg',
        'preview_path' => null,
        'poster_path' => null,
        'mime_type' => 'image/jpeg',
        'size' => 123,
        'not_found' => false,
    ]);

    Http::fake([
        $file->referrer_url => Http::response('', 200),
    ]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/preview-assets");

    $response->assertAccepted()
        ->assertJsonPath('queued', true)
        ->assertJsonPath('action', 'redownload_queued')
        ->assertJsonPath('file.id', $file->id)
        ->assertJsonPath('file.downloaded', false)
        ->assertJsonPath('file.path', null)
        ->assertJsonPath('file.not_found', false);

    expect($file->fresh())
        ->downloaded->toBeFalse()
        ->path->toBeNull()
        ->preview_path->toBeNull()
        ->poster_path->toBeNull();

    Queue::assertPushed(DownloadFile::class, fn (DownloadFile $job): bool => $job->fileId === $file->id);
    Queue::assertNotPushed(GenerateFilePreviewAssets::class);
    Event::assertDispatched(FilePreviewAssetsUpdated::class, fn (FilePreviewAssetsUpdated $event): bool => $event->fileId === $file->id);
    expect(MediaProcessorTask::query()->where('file_id', $file->id)->exists())->toBeFalse();
});

test('missing disk originals with gone remote sources become unavailable', function () {
    /** @var \Tests\TestCase $this */
    /** @var \App\Models\User $admin */
    $admin = User::factory()->admin()->create();

    Queue::fake();
    Event::fake([FilePreviewAssetsUpdated::class]);
    Storage::fake(config('downloads.disk'));

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/example/gone.jpeg',
        'referrer_url' => 'https://civitai.com/images/404',
        'downloaded' => true,
        'downloaded_at' => now(),
        'path' => 'downloads/aa/bb/missing.jpg',
        'preview_path' => null,
        'poster_path' => null,
        'mime_type' => 'image/jpeg',
        'size' => 123,
        'not_found' => false,
    ]);

    Http::fake([
        $file->referrer_url => Http::response('', 404),
    ]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/preview-assets");

    $response->assertOk()
        ->assertJsonPath('queued', false)
        ->assertJsonPath('action', 'unavailable')
        ->assertJsonPath('file.preview_generation.status', 'unavailable')
        ->assertJsonPath('file.preview_generation.can_retry', false)
        ->assertJsonPath('file.not_found', true);

    $task = MediaProcessorTask::query()->where('file_id', $file->id)->first();

    expect($task)->not->toBeNull()
        ->and($task?->status)->toBe('failed')
        ->and($task?->error_code)->toBe('preview_redownload_not_found');

    Queue::assertNotPushed(DownloadFile::class);
    Queue::assertNotPushed(GenerateFilePreviewAssets::class);
    Event::assertDispatched(FilePreviewAssetsUpdated::class, fn (FilePreviewAssetsUpdated $event): bool => $event->fileId === $file->id);
});

test('missing local or imported originals become unavailable without redownload', function () {
    /** @var \Tests\TestCase $this */
    /** @var \App\Models\User $admin */
    $admin = User::factory()->admin()->create();

    Queue::fake();
    Event::fake([FilePreviewAssetsUpdated::class]);
    Storage::fake(config('downloads.disk'));

    $file = File::factory()->create([
        'source' => 'local',
        'downloaded' => false,
        'downloaded_at' => null,
        'imported_at' => now(),
        'path' => 'imports/aa/bb/missing.jpg',
        'preview_path' => null,
        'poster_path' => null,
        'mime_type' => 'image/jpeg',
        'size' => 123,
        'not_found' => false,
    ]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/preview-assets");

    $response->assertOk()
        ->assertJsonPath('queued', false)
        ->assertJsonPath('action', 'unavailable')
        ->assertJsonPath('file.preview_generation.status', 'unavailable')
        ->assertJsonPath('file.preview_generation.can_retry', false);

    $task = MediaProcessorTask::query()->where('file_id', $file->id)->first();

    expect($task)->not->toBeNull()
        ->and($task?->status)->toBe('failed')
        ->and($task?->error_code)->toBe('preview_redownload_unsupported');

    Queue::assertNotPushed(DownloadFile::class);
    Queue::assertNotPushed(GenerateFilePreviewAssets::class);
    Event::assertDispatched(FilePreviewAssetsUpdated::class, fn (FilePreviewAssetsUpdated $event): bool => $event->fileId === $file->id);
});

test('remote processor submission without immediate local paths is not treated as original corruption', function () {
    /** @var \Tests\TestCase $this */
    /** @var \App\Models\User $admin */
    $admin = User::factory()->admin()->create();

    Event::fake([FilePreviewAssetsUpdated::class]);
    Storage::fake(config('downloads.disk'));
    configurePreviewAssetsRemoteMediaProcessor();

    $bytes = validPreviewAssetsImageBytes();
    $file = File::factory()->create([
        'source' => 'CivitAI',
        'downloaded' => true,
        'downloaded_at' => now(),
        'path' => 'downloads/aa/bb/source.png',
        'preview_path' => null,
        'poster_path' => null,
        'mime_type' => 'image/png',
        'size' => strlen($bytes),
    ]);
    Storage::disk(config('downloads.disk'))->put($file->path, $bytes);

    Http::fake([
        'processor.test/tasks' => Http::response(['accepted' => true], 202),
    ]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/preview-assets");

    $response->assertAccepted()
        ->assertJsonPath('queued', true)
        ->assertJsonPath('action', 'preview_queued')
        ->assertJsonPath('file.preview_generation.status', 'queued');

    expect(MediaProcessorTask::query()->where('file_id', $file->id)->count())->toBe(1)
        ->and(MediaProcessorTask::query()->where('file_id', $file->id)->where('status', 'failed')->exists())->toBeFalse();

    Event::assertDispatched(FilePreviewAssetsUpdated::class, fn (FilePreviewAssetsUpdated $event): bool => $event->fileId === $file->id);
});

function fakePreviewAssetsVideoProbe(): void
{
    app()->instance(MediaProbeService::class, new class extends MediaProbeService
    {
        public function probe(string $absolutePath): array
        {
            return [
                'streams' => [
                    ['codec_type' => 'video', 'codec_name' => 'h264'],
                ],
            ];
        }
    });
}

function configurePreviewAssetsRemoteMediaProcessor(): void
{
    config()->set('app.url', 'https://atlas.test');
    config()->set('media_processor.enabled', true);
    config()->set('media_processor.url', 'https://processor.test');
    config()->set('media_processor.secret', 'test-secret');
    config()->set('media_processor.instance', 'local');
    config()->set('media_processor.storage_profile', 'atlas-local');
    config()->set('media_processor.websocket_required', true);
}

function validPreviewAssetsImageBytes(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true);
}
