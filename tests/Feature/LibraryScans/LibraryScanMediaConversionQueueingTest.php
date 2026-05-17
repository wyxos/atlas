<?php

use App\Enums\LibraryScanItemStatus;
use App\Enums\LibraryScanMediaTask as MediaTask;
use App\Jobs\LibraryScans\CreateLibraryScanStreamableVideo;
use App\Jobs\LibraryScans\GenerateLibraryScanPreviewAssets;
use App\Jobs\LibraryScans\NormalizeLibraryScanAudio;
use App\Jobs\LibraryScans\ProcessLibraryScanItem;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\LibraryScanItem;
use App\Models\LibraryScanMediaTask;
use App\Models\LibraryScanRun;
use App\Services\Downloads\FileDownloadPreviewAssetGenerator;
use App\Services\LibraryScans\LibraryScanFileParser;
use App\Services\LibraryScans\LibraryScanMediaProcessor;
use App\Services\LibraryScans\LibraryScanService;
use App\Services\LibraryScans\MediaProbeService;
use App\Support\AtlasStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('queues streamable video conversions on the media conversion queue', function () {

    Queue::fake([GenerateLibraryScanPreviewAssets::class, CreateLibraryScanStreamableVideo::class]);

    $run = LibraryScanRun::factory()->create([

        'status' => 'processing',

        'phase' => 'processing',

        'scan_completed_at' => now(),

    ]);

    $file = File::factory()->create([

        'path' => 'imports/aa/bb/video.mkv',

        'mime_type' => 'video/x-matroska',

    ]);

    $item = LibraryScanItem::factory()->create([

        'library_scan_run_id' => $run->id,

        'file_id' => $file->id,

        'status' => LibraryScanItemStatus::IMPORTED,

        'parser' => 'video',

    ]);

    $parser = \Mockery::mock(LibraryScanFileParser::class);

    $parser->shouldReceive('parse')

        ->once()

        ->andReturn([

            'updates' => [],

            'metadata' => [],

            'tasks' => [

                MediaTask::TASK_PREVIEW_ASSETS,

                MediaTask::TASK_VIDEO_STREAMABLE,

            ],

        ]);

    $this->app->instance(LibraryScanFileParser::class, $parser);

    (new ProcessLibraryScanItem($item->id))->handle(

        app(LibraryScanFileParser::class),

        app(LibraryScanService::class),

    );

    $videoTask = LibraryScanMediaTask::query()

        ->where('library_scan_item_id', $item->id)

        ->where('type', MediaTask::TASK_VIDEO_STREAMABLE)

        ->first();

    expect($videoTask)->not->toBeNull()

        ->and($item->fresh()->status)->toBe(LibraryScanItemStatus::PROCESSING);

    Queue::assertPushed(

        CreateLibraryScanStreamableVideo::class,

        fn (CreateLibraryScanStreamableVideo $job): bool => $job->queue === MediaTask::CONVERSION_QUEUE

            && $job->taskId === $videoTask->id,

    );

});

it('queues mp3 audio normalization on the media conversion queue', function () {

    Queue::fake([NormalizeLibraryScanAudio::class]);

    configureLibraryScanStorage();

    Storage::disk(AtlasStorage::DISK)->put('imports/aa/bb/track.mp3', 'audio');

    $run = LibraryScanRun::factory()->create([

        'status' => 'processing',

        'phase' => 'processing',

        'scan_completed_at' => now(),

    ]);

    $file = File::factory()->create([

        'path' => 'imports/aa/bb/track.mp3',

        'filename' => 'track.mp3',

        'mime_type' => 'audio/mpeg',

        'hash' => str_repeat('e', 64),

        'imported_at' => now(),

    ]);

    $item = LibraryScanItem::factory()->create([

        'library_scan_run_id' => $run->id,

        'file_id' => $file->id,

        'status' => LibraryScanItemStatus::IMPORTED,

        'parser' => 'audio',

    ]);

    $probe = \Mockery::mock(MediaProbeService::class);

    $probe->shouldReceive('probe')->once()->andReturn([]);

    $this->app->instance(MediaProbeService::class, $probe);

    (new ProcessLibraryScanItem($item->id))->handle(

        app(LibraryScanFileParser::class),

        app(LibraryScanService::class),

    );

    $audioTask = LibraryScanMediaTask::query()

        ->where('library_scan_item_id', $item->id)

        ->where('type', MediaTask::TASK_AUDIO_NORMALIZATION)

        ->first();

    expect($audioTask)->not->toBeNull()

        ->and($item->fresh()->status)->toBe(LibraryScanItemStatus::PROCESSING);

    Queue::assertPushed(

        NormalizeLibraryScanAudio::class,

        fn (NormalizeLibraryScanAudio $job): bool => $job->queue === MediaTask::CONVERSION_QUEUE

            && $job->taskId === $audioTask->id,

    );

});

it('uses the download preview generator when imported file parsing is forced to regenerate previews', function () {

    $root = configureLibraryScanStorage();

    Storage::disk(AtlasStorage::DISK)->put('imports/aa/bb/image.jpg', 'image');

    $file = File::factory()->create([

        'path' => 'imports/aa/bb/image.jpg',

        'mime_type' => 'image/jpeg',

        'hash' => str_repeat('b', 64),

        'imported_at' => now(),

        'preview_path' => 'imports/aa/bb/preview/old.jpg',

    ]);

    $preview = \Mockery::mock(FileDownloadPreviewAssetGenerator::class);

    $preview->shouldReceive('regeneratePreviewAssets')

        ->once()

        ->with(\Mockery::on(fn (File $parsedFile): bool => $parsedFile->is($file)))

        ->andReturn(['preview_path' => 'imports/aa/bb/preview/image.jpg']);

    $preview->shouldReceive('generatePreviewAssets')->never();

    $this->app->instance(FileDownloadPreviewAssetGenerator::class, $preview);

    $result = app(LibraryScanMediaProcessor::class)->generatePreviewAssets($file, regeneratePreviewAssets: true);

    expect($result)->toBe(['updates' => ['preview_path' => 'imports/aa/bb/preview/image.jpg']])

        ->and($file->fresh()->preview_path)->toBe('imports/aa/bb/preview/image.jpg')

        ->and(is_dir($root))->toBeTrue();

});

it('attempts mp3 normalization instead of treating mp3 as already normalized', function () {

    configureLibraryScanStorage();

    Storage::disk(AtlasStorage::DISK)->put('imports/aa/bb/track.mp3', 'audio');

    $file = File::factory()->create([

        'path' => 'imports/aa/bb/track.mp3',

        'filename' => 'track.mp3',

        'mime_type' => 'audio/mpeg',

        'hash' => str_repeat('f', 64),

        'imported_at' => now(),

    ]);

    $probe = \Mockery::mock(MediaProbeService::class);

    $probe->shouldReceive('resolveFfmpegPath')->once()->andReturn(null);

    $this->app->instance(MediaProbeService::class, $probe);

    app(LibraryScanMediaProcessor::class)->normalizeAudio($file);

})->throws(\RuntimeException::class, 'ffmpeg unavailable');

it('uses the generated import filename for audio conversions without variant suffixes', function () {

    configureLibraryScanStorage();

    Storage::disk(AtlasStorage::DISK)->put('imports/aa/bb/AbCdEf1234567890AbCdEf1234567890AbCdEf12.mp3', 'audio');

    $fakeFfmpeg = storage_path('framework/testing/fake-ffmpeg'.(PHP_OS_FAMILY === 'Windows' ? '.bat' : ''));

    if (PHP_OS_FAMILY === 'Windows') {

        file_put_contents($fakeFfmpeg, implode(PHP_EOL, [

            '@echo off',

            'set "out=%~1"',

            ':loop',

            'if "%~2"=="" goto done',

            'shift',

            'set "out=%~1"',

            'goto loop',

            ':done',

            'echo converted>"%out%"',

            'exit /b 0',

        ]));

    } else {

        file_put_contents($fakeFfmpeg, implode(PHP_EOL, [

            '#!/bin/sh',

            'for last do :; done',

            'mkdir -p "$(dirname "$last")"',

            'printf converted > "$last"',

        ]));

        chmod($fakeFfmpeg, 0755);

    }

    $file = File::factory()->create([

        'path' => 'imports/aa/bb/AbCdEf1234567890AbCdEf1234567890AbCdEf12.mp3',

        'filename' => 'AbCdEf1234567890AbCdEf1234567890AbCdEf12.mp3',

        'mime_type' => 'audio/mpeg',

        'hash' => str_repeat('f', 64),

        'imported_at' => now(),

    ]);

    $probe = \Mockery::mock(MediaProbeService::class);

    $probe->shouldReceive('resolveFfmpegPath')->once()->andReturn($fakeFfmpeg);

    $this->app->instance(MediaProbeService::class, $probe);

    $result = app(LibraryScanMediaProcessor::class)->normalizeAudio($file);

    expect($result)->toBe([

        'normalized_audio' => 'imports/aa/bb/conversions/AbCdEf1234567890AbCdEf1234567890AbCdEf12.mp3',

    ]);

    Storage::disk(AtlasStorage::DISK)->assertExists($result['normalized_audio']);

    expect($result['normalized_audio'])->not->toContain('.normalized.');

});

it('skips streamable video conversion work for browser-supported imported mp4 files regardless of resolution', function () {

    configureLibraryScanStorage();

    Storage::disk(AtlasStorage::DISK)->put('imports/aa/bb/video.mp4', 'video');

    $file = File::factory()->create([

        'path' => 'imports/aa/bb/video.mp4',

        'filename' => 'video.mp4',

        'mime_type' => 'video/mp4',

        'hash' => str_repeat('c', 64),

        'imported_at' => now(),

    ]);

    $probe = \Mockery::mock(MediaProbeService::class);

    $probe->shouldReceive('probe')->once()->andReturn([

        'streams' => [

            [

                'codec_type' => 'video',

                'codec_name' => 'h264',

                'width' => 3840,

                'height' => 2160,

            ],

        ],

    ]);

    $this->app->instance(MediaProbeService::class, $probe);

    $result = app(LibraryScanFileParser::class)->parse($file, 'video');

    expect($result['tasks'])->toBe([

        MediaTask::TASK_PREVIEW_ASSETS,

    ])

        ->and($result['metadata'])->not->toHaveKey('conversions');

    expect(FileMetadata::query()->where('file_id', $file->id)->exists())->toBeTrue();

});
