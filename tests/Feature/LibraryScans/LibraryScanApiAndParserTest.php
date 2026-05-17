<?php

use App\Enums\LibraryScanItemStatus;
use App\Enums\LibraryScanMediaTask as MediaTask;
use App\Enums\LibraryScanRunMode;
use App\Jobs\LibraryScans\GenerateLibraryScanPreviewAssets;
use App\Jobs\LibraryScans\ImportLibraryScanItem;
use App\Jobs\LibraryScans\ProcessLibraryScanItem;
use App\Jobs\LibraryScans\ReparseImportedFilesRun;
use App\Jobs\LibraryScans\ScanLibraryRun;
use App\Models\File;
use App\Models\LibraryScanItem;
use App\Models\LibraryScanMediaTask;
use App\Models\LibraryScanRun;
use App\Models\User;
use App\Services\LibraryScans\LibraryScanFileParser;
use App\Services\LibraryScans\LibraryScanItemImporter;
use App\Services\LibraryScans\LibraryScanService;
use App\Support\AtlasStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('requires authentication for library scan APIs', function () {

    $this->postJson('/api/settings/library-scans')->assertUnauthorized();

});

it('starts a library scan from settings', function () {

    Queue::fake([ScanLibraryRun::class]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/settings/library-scans');

    $response->assertAccepted()

        ->assertJsonPath('run.status', 'pending');

    Queue::assertPushed(ScanLibraryRun::class, fn (ScanLibraryRun $job): bool => $job->queue === 'library-scans');

});

it('starts an imported file parser rerun from settings', function () {

    Queue::fake([ReparseImportedFilesRun::class]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/settings/library-scans/reparse-imported');

    $response->assertAccepted()

        ->assertJsonPath('run.mode', LibraryScanRunMode::REPARSE)

        ->assertJsonPath('run.status', 'pending');

    Queue::assertPushed(ReparseImportedFilesRun::class, fn (ReparseImportedFilesRun $job): bool => $job->queue === 'library-scans');

});

it('dispatches library scan parser jobs on the dedicated queue', function () {

    Queue::fake([ProcessLibraryScanItem::class]);

    $run = LibraryScanRun::factory()->create();

    $file = File::factory()->create();

    LibraryScanItem::factory()->create([

        'library_scan_run_id' => $run->id,

        'file_id' => $file->id,

        'status' => LibraryScanItemStatus::COMPLETED,

        'parser' => 'audio',

    ]);

    app(LibraryScanService::class)->dispatchPendingParsers($run);

    Queue::assertPushed(

        ProcessLibraryScanItem::class,

        fn (ProcessLibraryScanItem $job): bool => $job->queue === ProcessLibraryScanItem::QUEUE

            && $job->regeneratePreviewAssets === false,

    );

    expect(LibraryScanItem::query()->first()->parser_queued_at)->not->toBeNull();

});

it('completes scan progress after import while conversion parsing stays queued separately', function () {

    Queue::fake([ProcessLibraryScanItem::class]);

    $root = configureLibraryScanStorage();

    file_put_contents(

        $root.DIRECTORY_SEPARATOR.'image.png',

        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='),

    );

    $run = LibraryScanRun::factory()->create();

    (new ScanLibraryRun($run->id))->handle(app(AtlasStorage::class), app(LibraryScanService::class));

    $run = $run->fresh();

    $item = LibraryScanItem::query()->first();

    expect($run->status)->toBe('completed')

        ->and($run->files_found)->toBe(1)

        ->and($run->files_processed)->toBe(1)

        ->and($item->status)->toBe(LibraryScanItemStatus::COMPLETED)

        ->and($item->progress)->toBe(100)

        ->and($item->parser)->toBe('image');

    Queue::assertPushed(

        ProcessLibraryScanItem::class,

        fn (ProcessLibraryScanItem $job): bool => $job->queue === ProcessLibraryScanItem::QUEUE

            && $job->itemId === $item->id,

    );

});

it('queues the parser when an import finishes even while other scan imports remain', function () {

    Queue::fake([ProcessLibraryScanItem::class]);

    $root = configureLibraryScanStorage();

    $imagePath = $root.DIRECTORY_SEPARATOR.'image.png';

    file_put_contents(

        $imagePath,

        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='),

    );

    $run = LibraryScanRun::factory()->create([

        'status' => 'processing',

        'phase' => 'processing',

        'files_found' => 2,

        'scan_completed_at' => now(),

    ]);

    $item = LibraryScanItem::query()->create([

        'library_scan_run_id' => $run->id,

        'original_path' => $imagePath,

        'status' => LibraryScanItemStatus::PENDING,

        'phase' => 'discovered',

    ]);

    LibraryScanItem::factory()->create([

        'library_scan_run_id' => $run->id,

        'status' => LibraryScanItemStatus::PENDING,

        'phase' => 'discovered',

    ]);

    (new ImportLibraryScanItem($item->id))->handle(

        app(AtlasStorage::class),

        app(LibraryScanItemImporter::class),

        app(LibraryScanService::class),

    );

    $item = $item->fresh();

    expect($run->fresh()->status)->toBe('processing')

        ->and($run->fresh()->files_processed)->toBe(1)

        ->and($item->status)->toBe(LibraryScanItemStatus::COMPLETED)

        ->and($item->parser)->toBe('image')

        ->and($item->parser_queued_at)->not->toBeNull();

    Queue::assertPushed(

        ProcessLibraryScanItem::class,

        fn (ProcessLibraryScanItem $job): bool => $job->queue === ProcessLibraryScanItem::QUEUE

            && $job->itemId === $item->id,

    );

});

it('queues imported file parser reruns with preview regeneration', function () {

    Queue::fake([ProcessLibraryScanItem::class]);

    $run = LibraryScanRun::factory()->create([

        'mode' => LibraryScanRunMode::REPARSE,

        'status' => 'pending',

        'phase' => 'reparse_pending',

    ]);

    $file = File::factory()->create([

        'source' => 'local',

        'path' => 'imports/aa/bb/video.mp4',

        'filename' => 'video.mp4',

        'mime_type' => 'video/mp4',

        'hash' => str_repeat('a', 64),

        'imported_at' => now(),

    ]);

    (new ReparseImportedFilesRun($run->id))->handle(app(LibraryScanService::class));

    $item = LibraryScanItem::query()->where('file_id', $file->id)->first();

    expect($item)->not->toBeNull()

        ->and($item->parser)->toBe('video')

        ->and($item->status)->toBe(LibraryScanItemStatus::IMPORTED);

    Queue::assertPushed(

        ProcessLibraryScanItem::class,

        fn (ProcessLibraryScanItem $job): bool => $job->queue === ProcessLibraryScanItem::QUEUE

            && $job->itemId === $item->id

            && $job->regeneratePreviewAssets === true,

    );

});

it('queues imported file preview regeneration as media work before completing the parser item', function () {

    Queue::fake([GenerateLibraryScanPreviewAssets::class]);

    $run = LibraryScanRun::factory()->create([

        'mode' => LibraryScanRunMode::REPARSE,

        'status' => 'processing',

        'phase' => 'processing',

        'scan_completed_at' => now(),

    ]);

    $file = File::factory()->create([

        'path' => 'imports/aa/bb/image.jpg',

        'mime_type' => 'image/jpeg',

        'imported_at' => now(),

        'preview_path' => 'imports/aa/bb/preview/old.jpg',

    ]);

    $item = LibraryScanItem::factory()->create([

        'library_scan_run_id' => $run->id,

        'file_id' => $file->id,

        'status' => LibraryScanItemStatus::IMPORTED,

        'parser' => 'image',

    ]);

    $parser = \Mockery::mock(LibraryScanFileParser::class);

    $parser->shouldReceive('parse')

        ->once()

        ->with(\Mockery::on(fn (File $parsedFile): bool => $parsedFile->is($file)), 'image', true)

        ->andReturn([

            'updates' => [],

            'metadata' => [],

            'tasks' => [MediaTask::TASK_PREVIEW_ASSETS],

        ]);

    $this->app->instance(LibraryScanFileParser::class, $parser);

    (new ProcessLibraryScanItem($item->id, regeneratePreviewAssets: true))->handle(

        app(LibraryScanFileParser::class),

        app(LibraryScanService::class),

    );

    $task = LibraryScanMediaTask::query()->where('library_scan_item_id', $item->id)->first();

    expect($item->fresh()->status)->toBe(LibraryScanItemStatus::PROCESSING)

        ->and($task)->not->toBeNull()

        ->and($task->type)->toBe(MediaTask::TASK_PREVIEW_ASSETS)

        ->and($task->status)->toBe(MediaTask::STATUS_PENDING);

    Queue::assertPushed(

        GenerateLibraryScanPreviewAssets::class,

        fn (GenerateLibraryScanPreviewAssets $job): bool => $job->queue === MediaTask::PREVIEW_QUEUE

            && $job->taskId === $task->id

            && $job->regeneratePreviewAssets === true,

    );

});

it('keeps completed scan items completed while media conversion tasks run separately', function () {

    Queue::fake([GenerateLibraryScanPreviewAssets::class]);

    $run = LibraryScanRun::factory()->create([

        'mode' => LibraryScanRunMode::SCAN,

        'status' => 'completed',

        'phase' => 'completed',

        'scan_completed_at' => now(),

        'finished_at' => now(),

    ]);

    $file = File::factory()->create([

        'path' => 'imports/aa/bb/image.jpg',

        'mime_type' => 'image/jpeg',

        'imported_at' => now(),

    ]);

    $item = LibraryScanItem::factory()->create([

        'library_scan_run_id' => $run->id,

        'file_id' => $file->id,

        'status' => LibraryScanItemStatus::COMPLETED,

        'phase' => 'imported',

        'progress' => 100,

        'parser' => 'image',

    ]);

    $parser = \Mockery::mock(LibraryScanFileParser::class);

    $parser->shouldReceive('parse')

        ->once()

        ->andReturn([

            'updates' => [],

            'metadata' => [],

            'tasks' => [MediaTask::TASK_PREVIEW_ASSETS],

        ]);

    $this->app->instance(LibraryScanFileParser::class, $parser);

    (new ProcessLibraryScanItem($item->id))->handle(

        app(LibraryScanFileParser::class),

        app(LibraryScanService::class),

    );

    $task = LibraryScanMediaTask::query()->where('library_scan_item_id', $item->id)->first();

    expect($task)->not->toBeNull()

        ->and($task->status)->toBe(MediaTask::STATUS_PENDING)

        ->and($item->fresh()->status)->toBe(LibraryScanItemStatus::COMPLETED)

        ->and($item->fresh()->progress)->toBe(100)

        ->and($run->fresh()->status)->toBe('completed');

    Queue::assertPushed(

        GenerateLibraryScanPreviewAssets::class,

        fn (GenerateLibraryScanPreviewAssets $job): bool => $job->queue === MediaTask::PREVIEW_QUEUE

            && $job->taskId === $task->id,

    );

});
