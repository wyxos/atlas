<?php

use App\Enums\LibraryScanItemStatus;
use App\Enums\LibraryScanMediaTask as MediaTask;
use App\Enums\LibraryScanRunMode;
use App\Enums\LibraryScanRunStatus;
use App\Jobs\GenerateAudioMetadataRun;
use App\Jobs\LibraryScans\NormalizeLibraryScanAudio;
use App\Jobs\LibraryScans\ProcessLibraryScanItem;
use App\Models\File;
use App\Models\LibraryScanItem;
use App\Models\LibraryScanMediaTask;
use App\Models\LibraryScanRun;
use App\Services\LibraryScans\LibraryScanMediaProcessor;
use App\Services\LibraryScans\LibraryScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('parks media conversion work while a scan is paused', function () {

    $run = LibraryScanRun::factory()->create([

        'mode' => LibraryScanRunMode::SCAN,

        'status' => LibraryScanRunStatus::PAUSED,

        'phase' => 'processing',

        'scan_completed_at' => now(),

    ]);

    $file = File::factory()->create([

        'path' => 'imports/aa/bb/track.mp3',

        'mime_type' => 'audio/mpeg',

    ]);

    $item = LibraryScanItem::factory()->create([

        'library_scan_run_id' => $run->id,

        'file_id' => $file->id,

        'status' => LibraryScanItemStatus::COMPLETED,

        'parser' => 'audio',

    ]);

    $task = LibraryScanMediaTask::factory()->create([

        'library_scan_item_id' => $item->id,

        'file_id' => $file->id,

        'type' => MediaTask::TASK_AUDIO_NORMALIZATION,

        'status' => MediaTask::STATUS_PENDING,

        'phase' => MediaTask::PHASE_QUEUED,

    ]);

    $processor = \Mockery::mock(LibraryScanMediaProcessor::class);

    $processor->shouldReceive('normalizeAudio')->never();

    $this->app->instance(LibraryScanMediaProcessor::class, $processor);

    (new NormalizeLibraryScanAudio($task->id))->handle(

        app(LibraryScanMediaProcessor::class),

        app(LibraryScanService::class),

    );

    expect($task->fresh()->status)->toBe(MediaTask::STATUS_PENDING)

        ->and($task->fresh()->phase)->toBe(MediaTask::PHASE_PAUSED)

        ->and($task->fresh()->progress)->toBe(0)

        ->and($item->fresh()->status)->toBe(LibraryScanItemStatus::COMPLETED);

});

it('dispatches paused media conversion work when a scan resumes', function () {

    Queue::fake([NormalizeLibraryScanAudio::class]);

    $run = LibraryScanRun::factory()->create([

        'mode' => LibraryScanRunMode::SCAN,

        'status' => LibraryScanRunStatus::PAUSED,

        'phase' => 'processing',

        'scan_completed_at' => now(),

    ]);

    $file = File::factory()->create([

        'path' => 'imports/aa/bb/track.mp3',

        'mime_type' => 'audio/mpeg',

    ]);

    $item = LibraryScanItem::factory()->create([

        'library_scan_run_id' => $run->id,

        'file_id' => $file->id,

        'status' => LibraryScanItemStatus::COMPLETED,

    ]);

    $task = LibraryScanMediaTask::factory()->create([

        'library_scan_item_id' => $item->id,

        'file_id' => $file->id,

        'type' => MediaTask::TASK_AUDIO_NORMALIZATION,

        'status' => MediaTask::STATUS_PENDING,

        'phase' => MediaTask::PHASE_PAUSED,

    ]);

    app(LibraryScanService::class)->resume($run);

    expect($task->fresh()->phase)->toBe(MediaTask::PHASE_QUEUED);

    Queue::assertPushed(

        NormalizeLibraryScanAudio::class,

        fn (NormalizeLibraryScanAudio $job): bool => $job->queue === MediaTask::CONVERSION_QUEUE

            && $job->taskId === $task->id,

    );

});

it('uses separate horizon queues for library scan media previews and conversions', function () {

    expect(config('horizon.defaults.supervisor-media-previews.queue'))->toBe([MediaTask::PREVIEW_QUEUE])

        ->and(config('horizon.defaults.supervisor-media-conversions.queue'))->toBe([MediaTask::CONVERSION_QUEUE])

        ->and(config('horizon.defaults.supervisor-media-conversions.timeout'))->toBe(21600)

        ->and(config('horizon.defaults.supervisor-library-scans.connection'))->toBe('redis-library-scans')

        ->and(config('horizon.defaults.supervisor-library-scans.timeout'))->toBe(1800)

        ->and(config('horizon.defaults.supervisor-audio-metadata.connection'))->toBe('redis-audio-metadata')

        ->and(config('horizon.defaults.supervisor-audio-metadata.queue'))->toBe([GenerateAudioMetadataRun::QUEUE])

        ->and(config('horizon.defaults.supervisor-audio-metadata.timeout'))->toBe(1800)

        ->and(config('horizon.waits.'.GenerateAudioMetadataRun::CONNECTION.':'.GenerateAudioMetadataRun::QUEUE))->toBe(600)

        ->and(config('horizon.defaults.supervisor-library-scan-parsers.connection'))->toBe('redis-library-scan-parsers')

        ->and(config('horizon.defaults.supervisor-library-scan-parsers.queue'))->toBe([ProcessLibraryScanItem::QUEUE])

        ->and(config('queue.connections.redis-library-scans.retry_after'))->toBe(1920)

        ->and(config('queue.connections.redis-audio-metadata.queue'))->toBe(GenerateAudioMetadataRun::QUEUE)

        ->and(config('queue.connections.redis-audio-metadata.retry_after'))->toBe(1920)

        ->and(config('queue.connections.redis-library-scan-parsers.retry_after'))->toBe(420);

});
