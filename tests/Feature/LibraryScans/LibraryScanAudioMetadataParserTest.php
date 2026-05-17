<?php

use App\Enums\LibraryScanMediaTask as MediaTask;
use App\Enums\LibraryScanRunMode;
use App\Jobs\LibraryScans\ProcessLibraryScanItem;
use App\Jobs\LibraryScans\ReparseImportedFilesRun;
use App\Models\Album;
use App\Models\Artist;
use App\Models\File;
use App\Models\LibraryScanItem;
use App\Models\LibraryScanRun;
use App\Models\User;
use App\Services\LibraryScans\LibraryScanFileParser;
use App\Services\LibraryScans\LibraryScanService;
use App\Services\LibraryScans\MediaProbeService;
use App\Support\AtlasStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('starts an imported audio parser rerun from settings', function () {

    Queue::fake([ReparseImportedFilesRun::class]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/settings/library-scans/reparse-imported/audio');

    $response->assertAccepted()

        ->assertJsonPath('run.mode', LibraryScanRunMode::REPARSE)

        ->assertJsonPath('run.parser_filter', 'audio')

        ->assertJsonPath('run.phase', 'audio_reparse_pending');

    Queue::assertPushed(ReparseImportedFilesRun::class, fn (ReparseImportedFilesRun $job): bool => $job->queue === 'library-scans');

});

it('normalizes audio parser metadata into catalog relationships', function () {

    configureLibraryScanStorage();
    Storage::disk(AtlasStorage::DISK)->put('imports/aa/bb/track.mp3', 'audio payload');

    $probe = \Mockery::mock(MediaProbeService::class);
    $probe->shouldReceive('probe')
        ->once()
        ->andReturn([
            'format' => [
                'tags' => [
                    'title' => 'Tagged Title',
                    'artist' => 'Artist A; Artist B',
                    'album' => 'Album Name',
                ],
            ],
            'streams' => [],
        ]);
    $this->app->instance(MediaProbeService::class, $probe);

    $file = File::factory()->create([
        'source' => 'local',
        'path' => 'imports/aa/bb/track.mp3',
        'filename' => 'track.mp3',
        'mime_type' => 'audio/mpeg',
        'title' => 'track',
        'imported_at' => now(),
    ]);

    $result = app(LibraryScanFileParser::class)->parse($file, 'audio');

    expect($result['tasks'])->toBe([MediaTask::TASK_AUDIO_NORMALIZATION])
        ->and($file->fresh()->title)->toBe('Tagged Title')
        ->and(Artist::query()->pluck('name')->sort()->values()->all())->toBe(['Artist A', 'Artist B'])
        ->and(Album::query()->pluck('name')->values()->all())->toBe(['Album Name'])
        ->and($file->fresh()->artists()->pluck('name')->sort()->values()->all())->toBe(['Artist A', 'Artist B'])
        ->and($file->fresh()->albums()->pluck('name')->values()->all())->toBe(['Album Name']);

});

it('reuses matching albums for the same parsed artist', function () {

    configureLibraryScanStorage();
    Storage::disk(AtlasStorage::DISK)->put('imports/aa/bb/track-one.mp3', 'audio payload');
    Storage::disk(AtlasStorage::DISK)->put('imports/aa/bb/track-two.mp3', 'audio payload');

    $probe = \Mockery::mock(MediaProbeService::class);
    $probe->shouldReceive('probe')
        ->twice()
        ->andReturn([
            'format' => [
                'tags' => [
                    'artist' => 'Artist A',
                    'album' => 'Album Name',
                ],
            ],
            'streams' => [],
        ]);
    $this->app->instance(MediaProbeService::class, $probe);

    $firstFile = File::factory()->create([
        'source' => 'local',
        'path' => 'imports/aa/bb/track-one.mp3',
        'filename' => 'track-one.mp3',
        'mime_type' => 'audio/mpeg',
        'imported_at' => now(),
    ]);
    $secondFile = File::factory()->create([
        'source' => 'local',
        'path' => 'imports/aa/bb/track-two.mp3',
        'filename' => 'track-two.mp3',
        'mime_type' => 'audio/mpeg',
        'imported_at' => now(),
    ]);

    $parser = app(LibraryScanFileParser::class);
    $parser->parse($firstFile, 'audio');
    $parser->parse($secondFile, 'audio');

    $albumId = Album::query()->sole()->id;

    expect($firstFile->fresh()->albums()->value('albums.id'))->toBe($albumId)
        ->and($secondFile->fresh()->albums()->value('albums.id'))->toBe($albumId);

});

it('does not merge the same album title across different parsed artists', function () {

    configureLibraryScanStorage();
    Storage::disk(AtlasStorage::DISK)->put('imports/aa/bb/artist-a-track.mp3', 'audio payload');
    Storage::disk(AtlasStorage::DISK)->put('imports/aa/bb/artist-b-track.mp3', 'audio payload');

    $probe = \Mockery::mock(MediaProbeService::class);
    $probe->shouldReceive('probe')
        ->once()
        ->andReturn([
            'format' => [
                'tags' => [
                    'artist' => 'Artist A',
                    'album' => 'Greatest Hits',
                ],
            ],
            'streams' => [],
        ]);
    $probe->shouldReceive('probe')
        ->once()
        ->andReturn([
            'format' => [
                'tags' => [
                    'artist' => 'Artist B',
                    'album' => 'Greatest Hits',
                ],
            ],
            'streams' => [],
        ]);
    $this->app->instance(MediaProbeService::class, $probe);

    $firstFile = File::factory()->create([
        'source' => 'local',
        'path' => 'imports/aa/bb/artist-a-track.mp3',
        'filename' => 'artist-a-track.mp3',
        'mime_type' => 'audio/mpeg',
        'imported_at' => now(),
    ]);
    $secondFile = File::factory()->create([
        'source' => 'local',
        'path' => 'imports/aa/bb/artist-b-track.mp3',
        'filename' => 'artist-b-track.mp3',
        'mime_type' => 'audio/mpeg',
        'imported_at' => now(),
    ]);

    $parser = app(LibraryScanFileParser::class);
    $parser->parse($firstFile, 'audio');
    $parser->parse($secondFile, 'audio');

    $albums = Album::query()->orderBy('id')->get();

    expect($albums)->toHaveCount(2)
        ->and($albums->pluck('name')->all())->toBe(['Greatest Hits', 'Greatest Hits'])
        ->and($firstFile->fresh()->albums()->value('albums.id'))->toBe($albums[0]->id)
        ->and($secondFile->fresh()->albums()->value('albums.id'))->toBe($albums[1]->id);

});

it('limits imported file parser reruns to audio when requested', function () {

    Queue::fake([ProcessLibraryScanItem::class]);

    $run = LibraryScanRun::factory()->create([

        'mode' => LibraryScanRunMode::REPARSE,

        'parser_filter' => 'audio',

        'status' => 'pending',

        'phase' => 'audio_reparse_pending',

    ]);

    $audio = File::factory()->create([

        'path' => 'imports/aa/bb/audio.mp3',

        'filename' => 'audio.mp3',

        'mime_type' => 'audio/mpeg',

        'hash' => str_repeat('a', 64),

        'imported_at' => now(),

    ]);

    File::factory()->create([

        'path' => 'imports/aa/bb/image.jpg',

        'filename' => 'image.jpg',

        'mime_type' => 'image/jpeg',

        'hash' => str_repeat('b', 64),

        'imported_at' => now(),

    ]);

    (new ReparseImportedFilesRun($run->id))->handle(app(LibraryScanService::class));

    expect(LibraryScanItem::query()->count())->toBe(1)

        ->and(LibraryScanItem::query()->first()?->file_id)->toBe($audio->id)

        ->and(LibraryScanItem::query()->first()?->parser)->toBe('audio');

    Queue::assertPushed(

        ProcessLibraryScanItem::class,

        fn (ProcessLibraryScanItem $job): bool => $job->queue === ProcessLibraryScanItem::QUEUE,

    );

});
