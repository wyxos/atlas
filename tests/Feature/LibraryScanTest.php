<?php

use App\Enums\LibraryScanItemStatus;
use App\Enums\LibraryScanMediaTask as MediaTask;
use App\Enums\LibraryScanRunMode;
use App\Jobs\LibraryScans\CreateLibraryScanStreamableVideo;
use App\Jobs\LibraryScans\GenerateLibraryScanPreviewAssets;
use App\Jobs\LibraryScans\NormalizeLibraryScanAudio;
use App\Jobs\LibraryScans\ProcessLibraryScanItem;
use App\Jobs\LibraryScans\ReparseImportedFilesRun;
use App\Jobs\LibraryScans\ScanLibraryRun;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\LibraryScanItem;
use App\Models\LibraryScanMediaTask;
use App\Models\LibraryScanRun;
use App\Models\User;
use App\Services\Downloads\FileDownloadPreviewAssetGenerator;
use App\Services\LibraryScans\LibraryScanFileParser;
use App\Services\LibraryScans\LibraryScanMediaProcessor;
use App\Services\LibraryScans\LibraryScanService;
use App\Services\LibraryScans\MediaProbeService;
use App\Services\Local\LocalBrowseIndexSyncService;
use App\Support\AtlasStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function configureLibraryScanStorage(): string
{
    $root = storage_path('framework/testing/library-scan-'.Str::random(10));
    if (is_dir($root)) {
        Illuminate\Support\Facades\File::deleteDirectory($root);
    }

    config()->set('atlas.root', $root);
    config()->set('filesystems.disks.atlas.root', $root.DIRECTORY_SEPARATOR.'.app');
    Storage::forgetDisk(['atlas']);

    Illuminate\Support\Facades\File::ensureDirectoryExists($root);
    Illuminate\Support\Facades\File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'.app');

    return $root;
}

it('scans atlas root, excludes app storage, moves files to imports, and creates local file records', function () {
    Queue::fake([ProcessLibraryScanItem::class]);
    $root = configureLibraryScanStorage();
    Illuminate\Support\Facades\File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'loose');
    Illuminate\Support\Facades\File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'.app'.DIRECTORY_SEPARATOR.'downloads');
    file_put_contents($root.DIRECTORY_SEPARATOR.'loose'.DIRECTORY_SEPARATOR.'sample.txt', 'payload');
    file_put_contents($root.DIRECTORY_SEPARATOR.'.app'.DIRECTORY_SEPARATOR.'downloads'.DIRECTORY_SEPARATOR.'ignored.txt', 'ignored');

    $run = LibraryScanRun::factory()->create();

    (new ScanLibraryRun($run->id))->handle(app(AtlasStorage::class), app(LibraryScanService::class));

    $file = File::query()->first();
    $item = LibraryScanItem::query()->first();

    expect(File::query()->count())->toBe(1)
        ->and($file->source)->toBe('local')
        ->and($file->path)->toStartWith('imports/')
        ->and($file->downloaded)->toBeFalse()
        ->and($file->downloaded_at)->toBeNull()
        ->and($file->imported_at)->not->toBeNull()
        ->and($item->status)->toBe(LibraryScanItemStatus::COMPLETED)
        ->and($item->imported_path)->toBe($file->path)
        ->and(file_exists($root.DIRECTORY_SEPARATOR.'loose'.DIRECTORY_SEPARATOR.'sample.txt'))->toBeFalse()
        ->and(is_dir($root.DIRECTORY_SEPARATOR.'loose'))->toBeFalse()
        ->and(file_exists($root.DIRECTORY_SEPARATOR.'.app'.DIRECTORY_SEPARATOR.'downloads'.DIRECTORY_SEPARATOR.'ignored.txt'))->toBeTrue();
});

it('moves duplicate files but reuses the first file row by hash', function () {
    Queue::fake([ProcessLibraryScanItem::class]);
    $root = configureLibraryScanStorage();
    file_put_contents($root.DIRECTORY_SEPARATOR.'first.txt', 'same payload');
    file_put_contents($root.DIRECTORY_SEPARATOR.'second.txt', 'same payload');

    $run = LibraryScanRun::factory()->create();

    (new ScanLibraryRun($run->id))->handle(app(AtlasStorage::class), app(LibraryScanService::class));

    expect(File::query()->count())->toBe(1)
        ->and(LibraryScanItem::query()->count())->toBe(2)
        ->and(LibraryScanItem::query()->where('duplicate', true)->count())->toBe(1)
        ->and(LibraryScanItem::query()->whereNotNull('imported_path')->count())->toBe(2);
});

it('skips filesystem metadata files and directories during library scans', function () {
    Queue::fake([ProcessLibraryScanItem::class]);
    $root = configureLibraryScanStorage();
    Illuminate\Support\Facades\File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'album');
    Illuminate\Support\Facades\File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'.git');
    Illuminate\Support\Facades\File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'@eaDir');
    file_put_contents($root.DIRECTORY_SEPARATOR.'album'.DIRECTORY_SEPARATOR.'track.mp3', 'audio payload');
    file_put_contents($root.DIRECTORY_SEPARATOR.'album'.DIRECTORY_SEPARATOR.'.gitignore', '*');
    file_put_contents($root.DIRECTORY_SEPARATOR.'album'.DIRECTORY_SEPARATOR.'Thumbs.db', 'thumbs');
    file_put_contents($root.DIRECTORY_SEPARATOR.'album'.DIRECTORY_SEPARATOR.'desktop.ini', 'desktop');
    file_put_contents($root.DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR.'config', 'git config');
    file_put_contents($root.DIRECTORY_SEPARATOR.'@eaDir'.DIRECTORY_SEPARATOR.'track.mp3@SynoEAStream', 'synology metadata');

    $run = LibraryScanRun::factory()->create();

    (new ScanLibraryRun($run->id))->handle(app(AtlasStorage::class), app(LibraryScanService::class));

    expect(File::query()->count())->toBe(1)
        ->and(LibraryScanItem::query()->count())->toBe(1)
        ->and(File::query()->first()->filename)->toMatch('/^[A-Za-z0-9]{40}\.mp3$/')
        ->and(File::query()->first()->filename)->not->toBe('track.mp3')
        ->and(file_exists($root.DIRECTORY_SEPARATOR.'album'.DIRECTORY_SEPARATOR.'track.mp3'))->toBeFalse()
        ->and(file_exists($root.DIRECTORY_SEPARATOR.'album'.DIRECTORY_SEPARATOR.'.gitignore'))->toBeFalse()
        ->and(file_exists($root.DIRECTORY_SEPARATOR.'album'.DIRECTORY_SEPARATOR.'Thumbs.db'))->toBeFalse()
        ->and(file_exists($root.DIRECTORY_SEPARATOR.'album'.DIRECTORY_SEPARATOR.'desktop.ini'))->toBeFalse()
        ->and(is_dir($root.DIRECTORY_SEPARATOR.'album'))->toBeFalse()
        ->and(file_exists($root.DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR.'config'))->toBeTrue()
        ->and(file_exists($root.DIRECTORY_SEPARATOR.'@eaDir'.DIRECTORY_SEPARATOR.'track.mp3@SynoEAStream'))->toBeTrue();
});

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
    LibraryScanItem::factory()->create([
        'library_scan_run_id' => $run->id,
        'status' => LibraryScanItemStatus::IMPORTED,
        'parser' => 'audio',
    ]);

    app(LibraryScanService::class)->dispatchPendingParsers($run);

    Queue::assertPushed(
        ProcessLibraryScanItem::class,
        fn (ProcessLibraryScanItem $job): bool => $job->queue === 'library-scans'
            && $job->regeneratePreviewAssets === false,
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
        fn (ProcessLibraryScanItem $job): bool => $job->queue === 'library-scans'
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

it('plans streamable video conversion work for oversized imported mp4 files without inline conversions', function () {
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
        MediaTask::TASK_VIDEO_STREAMABLE,
    ])
        ->and($result['metadata'])->not->toHaveKey('conversions');
    expect(FileMetadata::query()->where('file_id', $file->id)->exists())->toBeTrue();
});

it('completes a parser item after its media preview task completes', function () {
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

    $sync = \Mockery::mock(LocalBrowseIndexSyncService::class);
    $sync->shouldReceive('syncFilesByIds')->once()->with([$file->id])->andReturnNull();
    $this->app->instance(LocalBrowseIndexSyncService::class, $sync);

    (new GenerateLibraryScanPreviewAssets($task->id, regeneratePreviewAssets: true))->handle(
        app(LibraryScanMediaProcessor::class),
        app(LibraryScanService::class),
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
    $probe->shouldReceive('probe')->once()->andReturn([]);
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

it('uses separate horizon queues for library scan media previews and conversions', function () {
    expect(config('horizon.defaults.supervisor-media-previews.queue'))->toBe([MediaTask::PREVIEW_QUEUE])
        ->and(config('horizon.defaults.supervisor-media-conversions.queue'))->toBe([MediaTask::CONVERSION_QUEUE])
        ->and(config('horizon.defaults.supervisor-media-conversions.timeout'))->toBe(21600);
});
