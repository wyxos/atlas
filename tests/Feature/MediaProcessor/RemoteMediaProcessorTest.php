<?php

use App\Enums\LibraryScanItemStatus;
use App\Enums\LibraryScanMediaTask as LibraryMediaTask;
use App\Enums\LibraryScanRunStatus;
use App\Enums\MediaProcessorOperation;
use App\Enums\MediaProcessorTaskStatus;
use App\Jobs\LibraryScans\CreateLibraryScanStreamableVideo;
use App\Jobs\LibraryScans\NormalizeLibraryScanAudio;
use App\Models\File;
use App\Models\LibraryScanItem;
use App\Models\LibraryScanMediaTask;
use App\Models\LibraryScanRun;
use App\Models\MediaProcessorTask;
use App\Services\Downloads\FileDownloadFinalizer;
use App\Services\LibraryScans\MediaProbeService;
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

it('submits GIF image preview work with an animated preview extension', function () {
    configureRemoteMediaProcessor();
    Http::fake([
        'processor.test/tasks' => Http::response(['accepted' => true], 202),
    ]);
    Storage::fake('atlas');

    $hash = str_repeat('d', 40);
    $path = "downloads/dd/dd/{$hash}.gif";
    Storage::disk('atlas')->put($path, base64_decode('R0lGODlhAQABAIABAAAAAP///ywAAAAAAQABAAACAkQBADs='));
    $file = File::factory()->create([
        'path' => $path,
        'mime_type' => 'image/gif',
        'preview_path' => null,
        'poster_path' => null,
    ]);

    $updates = app(FileDownloadFinalizer::class)->generatePreviewAssets($file);

    expect($updates)->toBe([])
        ->and(MediaProcessorTask::query()->count())->toBe(1);

    $task = MediaProcessorTask::query()->firstOrFail();
    expect($task->operation)->toBe(MediaProcessorOperation::IMAGE_PREVIEW)
        ->and($task->input_path)->toBe($path)
        ->and($task->output_paths)->toBe([
            'preview_path' => "downloads/dd/dd/preview/{$hash}.gif",
        ]);

    Http::assertSent(function ($request) use ($hash): bool {
        $payload = $request->data();

        return $payload['input_path'] === "downloads/dd/dd/{$hash}.gif"
            && $payload['output_paths']['preview_path'] === "downloads/dd/dd/preview/{$hash}.gif";
    });
});

it('keeps WebP image preview work on the standard PNG preview extension', function () {
    configureRemoteMediaProcessor();
    Http::fake([
        'processor.test/tasks' => Http::response(['accepted' => true], 202),
    ]);
    Storage::fake('atlas');

    $hash = str_repeat('e', 40);
    $path = "downloads/ee/ee/{$hash}.webp";
    Storage::disk('atlas')->put($path, base64_decode('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA'));
    $file = File::factory()->create([
        'path' => $path,
        'mime_type' => 'image/webp',
        'preview_path' => null,
        'poster_path' => null,
    ]);

    app(FileDownloadFinalizer::class)->generatePreviewAssets($file);

    $task = MediaProcessorTask::query()->firstOrFail();
    expect($task->operation)->toBe(MediaProcessorOperation::IMAGE_PREVIEW)
        ->and($task->output_paths)->toBe([
            'preview_path' => "downloads/ee/ee/preview/{$hash}.png",
        ]);
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

it('reconciles in-flight tasks before sampling stale queued backlog', function () {
    configureRemoteMediaProcessor();
    Http::fake([
        'processor.test/tasks/*' => Http::response([
            'status' => 'processing',
            'phase' => 'processing',
            'progress' => 33,
        ], 200),
    ]);

    $staleSince = now()->subMinutes(10);
    $processingTasks = collect([
        createMediaProcessorTask(MediaProcessorTaskStatus::PROCESSING, $staleSince->copy()->addSecond()),
        createMediaProcessorTask(MediaProcessorTaskStatus::PROCESSING, $staleSince->copy()->addSeconds(2)),
        createMediaProcessorTask(MediaProcessorTaskStatus::PROCESSING, $staleSince->copy()->addSeconds(3)),
    ]);
    $firstQueued = createMediaProcessorTask(MediaProcessorTaskStatus::QUEUED, $staleSince->copy()->addSeconds(4));
    $secondQueued = createMediaProcessorTask(MediaProcessorTaskStatus::QUEUED, $staleSince->copy()->addSeconds(5));

    $this->artisan('atlas:reconcile-media-processor-tasks --limit=4 --pending-limit=1')
        ->expectsOutput('Polled 4 stale media processor task(s); 0 failed.')
        ->assertExitCode(0);

    Http::assertSentCount(4);
    expect($processingTasks->every(fn (MediaProcessorTask $task): bool => $task->fresh()?->progress === 33))->toBeTrue()
        ->and($firstQueued->fresh()?->status)->toBe(MediaProcessorTaskStatus::PROCESSING)
        ->and($secondQueued->fresh()?->status)->toBe(MediaProcessorTaskStatus::QUEUED);
});

it('reports stale in-flight and pending counts separately during media processor reconciliation dry runs', function () {
    configureRemoteMediaProcessor();

    createMediaProcessorTask(MediaProcessorTaskStatus::PROCESSING, now()->subMinutes(10));
    createMediaProcessorTask(MediaProcessorTaskStatus::QUEUED, now()->subMinutes(10));
    createMediaProcessorTask(MediaProcessorTaskStatus::SUBMITTING, now()->subMinutes(10));

    $this->artisan('atlas:reconcile-media-processor-tasks --dry-run')
        ->expectsOutput('Stale in-flight media processor tasks: 1')
        ->expectsOutput('Stale pending media processor tasks: 2')
        ->assertExitCode(0);
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

it('completes browser-supported silent MP4 streamable tasks without remote submission', function () {
    configureRemoteMediaProcessor();
    Http::fake();
    Storage::fake('atlas');
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

    $hash = str_repeat('f', 40);
    $path = "imports/ff/ff/{$hash}.mp4";
    Storage::disk('atlas')->put($path, 'synthetic-video');
    $file = File::factory()->create([
        'source' => 'local',
        'path' => $path,
        'mime_type' => 'video/mp4',
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
        'type' => LibraryMediaTask::TASK_VIDEO_STREAMABLE,
        'status' => LibraryMediaTask::STATUS_PENDING,
    ]);

    (new CreateLibraryScanStreamableVideo($mediaTask->id))->handle(
        app(\App\Services\LibraryScans\LibraryScanMediaProcessor::class),
        app(\App\Services\LibraryScans\LibraryScanService::class),
    );

    expect(MediaProcessorTask::query()->count())->toBe(0)
        ->and($mediaTask->fresh()?->status)->toBe(LibraryMediaTask::STATUS_COMPLETED)
        ->and($mediaTask->fresh()?->result)->toBe([]);
    Http::assertNothingSent();
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

function createMediaProcessorTask(string $status, mixed $lastEventAt): MediaProcessorTask
{
    $id = (string) Str::uuid();

    return MediaProcessorTask::query()->create([
        'id' => $id,
        'operation' => MediaProcessorOperation::IMAGE_PREVIEW,
        'status' => $status,
        'phase' => $status,
        'progress' => $status === MediaProcessorTaskStatus::PROCESSING ? 50 : 1,
        'storage_profile' => 'atlas-local',
        'input_path' => "downloads/{$id}.png",
        'output_paths' => [
            'preview_path' => "downloads/preview/{$id}.png",
        ],
        'last_event_at' => $lastEventAt,
    ]);
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
