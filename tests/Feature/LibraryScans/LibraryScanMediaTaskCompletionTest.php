<?php

use App\Enums\LibraryScanItemStatus;
use App\Enums\LibraryScanMediaTask as MediaTask;
use App\Jobs\LibraryScans\CreateLibraryScanStreamableVideo;
use App\Jobs\LibraryScans\GenerateLibraryScanPreviewAssets;
use App\Jobs\SyncLibraryIndex;
use App\Models\File;
use App\Models\LibraryScanItem;
use App\Models\LibraryScanMediaTask;
use App\Models\LibraryScanRun;
use App\Services\Downloads\FileDownloadPreviewAssetGenerator;
use App\Services\LibraryScans\LibraryScanMediaProcessor;
use App\Services\LibraryScans\LibraryScanService;
use App\Services\LibraryScans\MediaProbeService;
use App\Support\AtlasStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('completes a parser item after its media preview task completes', function () {

    Queue::fake([SyncLibraryIndex::class]);

    $run = LibraryScanRun::factory()->create([

        'status' => 'processing',

        'phase' => 'processing',

        'scan_completed_at' => now(),

    ]);

    $file = File::factory()->create([

        'path' => 'imports/aa/bb/image.jpg',

        'mime_type' => 'image/jpeg',

        'preview_path' => null,

    ]);

    $item = LibraryScanItem::factory()->create([

        'library_scan_run_id' => $run->id,

        'file_id' => $file->id,

        'status' => LibraryScanItemStatus::PROCESSING,

        'parser' => 'image',

    ]);

    $task = LibraryScanMediaTask::factory()->create([

        'library_scan_item_id' => $item->id,

        'file_id' => $file->id,

        'type' => MediaTask::TASK_PREVIEW_ASSETS,

        'status' => MediaTask::STATUS_PENDING,

    ]);

    $preview = \Mockery::mock(FileDownloadPreviewAssetGenerator::class);

    $preview->shouldReceive('regeneratePreviewAssets')

        ->once()

        ->with(\Mockery::on(fn (File $parsedFile): bool => $parsedFile->is($file)))

        ->andReturn(['preview_path' => 'imports/aa/bb/preview/image.jpg']);

    $preview->shouldReceive('generatePreviewAssets')->never();

    $this->app->instance(FileDownloadPreviewAssetGenerator::class, $preview);

    (new GenerateLibraryScanPreviewAssets($task->id, regeneratePreviewAssets: true))->handle(

        app(LibraryScanMediaProcessor::class),

        app(LibraryScanService::class),

    );

    Queue::assertPushed(

        SyncLibraryIndex::class,

        fn (SyncLibraryIndex $job): bool => $job->fileIds === [$file->id]

            && $job->syncFiles === true

            && $job->syncReactions === false

    );

    expect($task->fresh()->status)->toBe(MediaTask::STATUS_COMPLETED)

        ->and($item->fresh()->status)->toBe(LibraryScanItemStatus::COMPLETED)

        ->and($file->fresh()->preview_path)->toBe('imports/aa/bb/preview/image.jpg')

        ->and($run->fresh()->status)->toBe('completed');

});

it('fails the parser item when a conversion media task fails', function () {

    configureLibraryScanStorage();

    Storage::disk(AtlasStorage::DISK)->put('imports/aa/bb/video.mkv', 'video');

    $run = LibraryScanRun::factory()->create([

        'status' => 'processing',

        'phase' => 'processing',

        'scan_completed_at' => now(),

    ]);

    $file = File::factory()->create([

        'path' => 'imports/aa/bb/video.mkv',

        'filename' => 'video.mkv',

        'mime_type' => 'video/x-matroska',

        'hash' => str_repeat('d', 64),

    ]);

    $item = LibraryScanItem::factory()->create([

        'library_scan_run_id' => $run->id,

        'file_id' => $file->id,

        'status' => LibraryScanItemStatus::PROCESSING,

        'parser' => 'video',

    ]);

    $task = LibraryScanMediaTask::factory()->create([

        'library_scan_item_id' => $item->id,

        'file_id' => $file->id,

        'type' => MediaTask::TASK_VIDEO_STREAMABLE,

        'status' => MediaTask::STATUS_PENDING,

    ]);

    $probe = \Mockery::mock(MediaProbeService::class);

    $probe->shouldReceive('probe')->once()->andReturn([

        'streams' => [

            [

                'codec_type' => 'video',

                'codec_name' => 'h264',

            ],

        ],

    ]);

    $probe->shouldReceive('resolveFfmpegPath')->once()->andReturn(null);

    $this->app->instance(MediaProbeService::class, $probe);

    (new CreateLibraryScanStreamableVideo($task->id))->handle(

        app(LibraryScanMediaProcessor::class),

        app(LibraryScanService::class),

    );

    expect($task->fresh()->status)->toBe(MediaTask::STATUS_FAILED)

        ->and($item->fresh()->status)->toBe(LibraryScanItemStatus::FAILED)

        ->and($item->fresh()->error_code)->toBe('video_streamable_failed')

        ->and($run->fresh()->status)->toBe('failed');

});
