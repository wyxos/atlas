<?php

use App\Enums\MediaProcessorOperation;
use App\Enums\MediaProcessorTaskStatus;
use App\Events\FilePreviewAssetsUpdated;
use App\Jobs\GenerateFilePreviewAssets;
use App\Models\File;
use App\Models\MediaProcessorTask;
use App\Services\Downloads\FileDownloadFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

it('passes the force flag to preview generation and stores returned paths', function () {
    $file = File::factory()->create([
        'preview_path' => 'downloads/aa/bb/thumbnails/old.jpg',
        'poster_path' => null,
    ]);

    mock(FileDownloadFinalizer::class)
        ->shouldReceive('generatePreviewAssets')
        ->once()
        ->with(\Mockery::on(fn (File $argument): bool => $argument->is($file)), true)
        ->andReturn([
            'preview_path' => 'downloads/aa/bb/preview/new.jpg',
        ]);

    (new GenerateFilePreviewAssets($file->id, true))->handle(app(FileDownloadFinalizer::class));

    expect($file->fresh()?->preview_path)->toBe('downloads/aa/bb/preview/new.jpg');
});

it('broadcasts when preview generation submits a remote processor task without local paths', function () {
    Event::fake([FilePreviewAssetsUpdated::class]);

    $file = File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now(),
        'path' => 'downloads/aa/bb/source.png',
        'preview_path' => null,
        'poster_path' => null,
        'mime_type' => 'image/png',
    ]);

    mock(FileDownloadFinalizer::class)
        ->shouldReceive('generatePreviewAssets')
        ->once()
        ->with(\Mockery::on(fn (File $argument): bool => $argument->is($file)), true)
        ->andReturnUsing(function () use ($file): array {
            MediaProcessorTask::query()->create([
                'id' => (string) Str::uuid(),
                'file_id' => $file->id,
                'operation' => MediaProcessorOperation::IMAGE_PREVIEW,
                'status' => MediaProcessorTaskStatus::QUEUED,
                'phase' => 'queued',
                'progress' => 1,
                'storage_profile' => 'atlas-local',
                'input_path' => $file->path,
                'output_paths' => [
                    'preview_path' => 'downloads/aa/bb/preview/source.png',
                ],
                'attempts' => 1,
                'submitted_at' => now(),
                'last_event_at' => now(),
            ]);

            return [];
        });

    (new GenerateFilePreviewAssets($file->id, true))->handle(app(FileDownloadFinalizer::class));

    expect(MediaProcessorTask::query()->where('file_id', $file->id)->where('status', 'failed')->exists())->toBeFalse();
    Event::assertDispatched(FilePreviewAssetsUpdated::class, fn (FilePreviewAssetsUpdated $event): bool => $event->fileId === $file->id);
});

it('marks preview generation unavailable when an original is missing and the source is gone', function () {
    Event::fake([FilePreviewAssetsUpdated::class]);

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

    mock(FileDownloadFinalizer::class)
        ->shouldReceive('generatePreviewAssets')
        ->once()
        ->with(\Mockery::on(fn (File $argument): bool => $argument->is($file)), true)
        ->andReturn([]);

    (new GenerateFilePreviewAssets($file->id, true))->handle(app(FileDownloadFinalizer::class));

    $task = MediaProcessorTask::query()->where('file_id', $file->id)->first();

    expect($task)->not->toBeNull()
        ->and($task?->status)->toBe(MediaProcessorTaskStatus::FAILED)
        ->and($task?->error_code)->toBe('preview_redownload_not_found')
        ->and($file->fresh()?->not_found)->toBeTrue();
    Event::assertDispatched(FilePreviewAssetsUpdated::class, fn (FilePreviewAssetsUpdated $event): bool => $event->fileId === $file->id);
});
