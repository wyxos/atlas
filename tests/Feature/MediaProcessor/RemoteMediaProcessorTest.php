<?php

use App\Enums\LibraryScanItemStatus;
use App\Enums\LibraryScanMediaTask as LibraryMediaTask;
use App\Enums\LibraryScanRunStatus;
use App\Enums\MediaProcessorOperation;
use App\Enums\MediaProcessorTaskStatus;
use App\Jobs\LibraryScans\NormalizeLibraryScanAudio;
use App\Models\File;
use App\Models\LibraryScanItem;
use App\Models\LibraryScanMediaTask;
use App\Models\LibraryScanRun;
use App\Models\MediaProcessorTask;
use App\Services\Downloads\FileDownloadFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('submits downloaded video preview work to the remote processor with managed hash paths', function () {
    configureRemoteMediaProcessor();
    Http::fake([
        'processor.test/tasks' => Http::response(['accepted' => true], 202),
    ]);
    Storage::fake('atlas');

    $hash = str_repeat('a', 40);
    $path = "downloads/aa/aa/{$hash}.mp4";
    Storage::disk('atlas')->put($path, 'synthetic-video');
    $file = File::factory()->create([
        'path' => $path,
        'mime_type' => 'video/mp4',
        'preview_path' => null,
        'poster_path' => null,
    ]);

    $updates = app(FileDownloadFinalizer::class)->generatePreviewAssets($file);

    expect($updates)->toBe([])
        ->and(MediaProcessorTask::query()->count())->toBe(1);

    $task = MediaProcessorTask::query()->firstOrFail();
    expect($task->operation)->toBe(MediaProcessorOperation::VIDEO_PREVIEW)
        ->and($task->input_path)->toBe($path)
        ->and($task->output_paths)->toBe([
            'preview_path' => "downloads/aa/aa/preview/{$hash}.mp4",
            'poster_path' => "downloads/aa/aa/preview/{$hash}.jpg",
        ]);

    Http::assertSent(function ($request) use ($hash): bool {
        $payload = $request->data();

        return $request->hasHeader('X-Atlas-Signature')
            && $payload['storage_profile'] === 'atlas-local'
            && $payload['input_path'] === "downloads/aa/aa/{$hash}.mp4"
            && $payload['websocket_required'] === true;
    });
});

it('accepts a signed completion callback and stores preview paths', function () {
    configureRemoteMediaProcessor();

    $hash = str_repeat('b', 40);
    $file = File::factory()->create([
        'path' => "downloads/bb/bb/{$hash}.mp4",
        'mime_type' => 'video/mp4',
        'preview_path' => null,
        'poster_path' => null,
    ]);
    $task = MediaProcessorTask::query()->create([
        'id' => (string) Str::uuid(),
        'file_id' => $file->id,
        'operation' => MediaProcessorOperation::VIDEO_PREVIEW,
        'status' => MediaProcessorTaskStatus::PROCESSING,
        'phase' => 'processing',
        'progress' => 50,
        'storage_profile' => 'atlas-local',
        'input_path' => $file->path,
        'output_paths' => [
            'preview_path' => "downloads/bb/bb/preview/{$hash}.mp4",
            'poster_path' => "downloads/bb/bb/preview/{$hash}.jpg",
        ],
    ]);

    $uri = "/api/media-processor/tasks/{$task->id}/events";
    $body = json_encode([
        'task_id' => $task->id,
        'status' => 'completed',
        'progress' => 100,
        'result' => [
            'output_paths' => [
                'preview_path' => "downloads/bb/bb/preview/{$hash}.mp4",
                'poster_path' => "downloads/bb/bb/preview/{$hash}.jpg",
            ],
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this->call('POST', $uri, [], [], [], signedJsonServer($uri, $body), $body)
        ->assertOk();

    expect($task->fresh()?->status)->toBe(MediaProcessorTaskStatus::COMPLETED)
        ->and($file->fresh()?->preview_path)->toBe("downloads/bb/bb/preview/{$hash}.mp4")
        ->and($file->fresh()?->poster_path)->toBe("downloads/bb/bb/preview/{$hash}.jpg");
});

it('queues library scan audio normalization remotely without reading local media content', function () {
    configureRemoteMediaProcessor();
    Http::fake([
        'processor.test/tasks' => Http::response(['accepted' => true], 202),
    ]);

    $hash = str_repeat('c', 40);
    $file = File::factory()->create([
        'source' => 'local',
        'path' => "imports/cc/cc/{$hash}.mp3",
        'mime_type' => 'audio/mpeg',
        'preview_path' => null,
        'poster_path' => null,
    ]);
    $run = LibraryScanRun::factory()->create([
        'status' => LibraryScanRunStatus::PROCESSING,
    ]);
    $item = LibraryScanItem::factory()->create([
        'library_scan_run_id' => $run->id,
        'file_id' => $file->id,
        'original_path' => 'synthetic/source/hash',
        'status' => LibraryScanItemStatus::PROCESSING,
    ]);
    $mediaTask = LibraryScanMediaTask::factory()->create([
        'library_scan_item_id' => $item->id,
        'file_id' => $file->id,
        'type' => LibraryMediaTask::TASK_AUDIO_NORMALIZATION,
        'status' => LibraryMediaTask::STATUS_PENDING,
    ]);

    (new NormalizeLibraryScanAudio($mediaTask->id))->handle(
        app(\App\Services\LibraryScans\LibraryScanMediaProcessor::class),
        app(\App\Services\LibraryScans\LibraryScanService::class),
    );

    $remoteTask = MediaProcessorTask::query()->firstOrFail();
    expect($remoteTask->operation)->toBe(MediaProcessorOperation::AUDIO_NORMALIZATION)
        ->and($remoteTask->library_scan_media_task_id)->toBe($mediaTask->id)
        ->and($remoteTask->output_paths)->toBe([
            'normalized_audio' => "imports/cc/cc/conversions/{$hash}.mp3",
        ])
        ->and($mediaTask->fresh()?->status)->toBe(LibraryMediaTask::STATUS_PROCESSING);
});

function configureRemoteMediaProcessor(): void
{
    config()->set('app.url', 'https://atlas.test');
    config()->set('media_processor.enabled', true);
    config()->set('media_processor.url', 'https://processor.test');
    config()->set('media_processor.secret', 'test-secret');
    config()->set('media_processor.instance', 'local');
    config()->set('media_processor.storage_profile', 'atlas-local');
    config()->set('media_processor.websocket_required', true);
}

/**
 * @return array<string, string>
 */
function signedJsonServer(string $uri, string $body): array
{
    $timestamp = '1800000000';
    $signature = 'sha256='.hash_hmac(
        'sha256',
        "POST\n{$uri}\n{$timestamp}\n{$body}",
        'test-secret',
    );

    return [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_ATLAS_TIMESTAMP' => $timestamp,
        'HTTP_X_ATLAS_SIGNATURE' => $signature,
    ];
}
