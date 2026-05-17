<?php

use App\Enums\LibraryScanItemStatus;
use App\Jobs\LibraryScans\ImportLibraryScanItem;
use App\Jobs\LibraryScans\ProcessLibraryScanItem;
use App\Jobs\LibraryScans\ScanLibraryRun;
use App\Models\File;
use App\Models\LibraryScanItem;
use App\Models\LibraryScanRun;
use App\Models\Reaction;
use App\Models\User;
use App\Services\LibraryScans\LibraryScanItemImporter;
use App\Services\LibraryScans\LibraryScanService;
use App\Support\AtlasStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

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

it('excludes root typesense data from library scan discovery', function () {

    Queue::fake([ImportLibraryScanItem::class]);

    $root = configureLibraryScanStorage();

    Illuminate\Support\Facades\File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'typesense-data'.DIRECTORY_SEPARATOR.'db');

    file_put_contents($root.DIRECTORY_SEPARATOR.'typesense-data'.DIRECTORY_SEPARATOR.'db'.DIRECTORY_SEPARATOR.'state.log', 'typesense payload');

    file_put_contents($root.DIRECTORY_SEPARATOR.'sample.txt', 'payload');

    $run = LibraryScanRun::factory()->create();

    (new ScanLibraryRun($run->id))->handle(app(AtlasStorage::class), app(LibraryScanService::class));

    expect($run->fresh()->files_found)->toBe(1)

        ->and(LibraryScanItem::query()->count())->toBe(1)

        ->and(LibraryScanItem::query()->where('original_path', 'like', '%typesense-data%')->exists())->toBeFalse()

        ->and(file_exists($root.DIRECTORY_SEPARATOR.'typesense-data'.DIRECTORY_SEPARATOR.'db'.DIRECTORY_SEPARATOR.'state.log'))->toBeTrue();

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

        ->and(LibraryScanItem::query()->where('duplicate', true)->first()->parser)->toBeNull()

        ->and(LibraryScanItem::query()->whereNotNull('imported_path')->count())->toBe(2);

});

it('still deduplicates files within the current scan when the global files hash index is unavailable', function () {

    Queue::fake([ProcessLibraryScanItem::class]);

    Cache::put('library-scans:files-hash-index-exists', false, 600);

    $root = configureLibraryScanStorage();

    file_put_contents($root.DIRECTORY_SEPARATOR.'first.txt', 'same payload');

    file_put_contents($root.DIRECTORY_SEPARATOR.'second.txt', 'same payload');

    $run = LibraryScanRun::factory()->create();

    (new ScanLibraryRun($run->id))->handle(app(AtlasStorage::class), app(LibraryScanService::class));

    expect(File::query()->count())->toBe(1)

        ->and(LibraryScanItem::query()->where('duplicate', true)->count())->toBe(1);

});

it('skips the global files hash lookup when its supporting index is unavailable', function () {

    Queue::fake([ProcessLibraryScanItem::class]);

    Cache::put('library-scans:files-hash-index-exists', false, 600);

    $root = configureLibraryScanStorage();

    $payload = 'same as existing indexed file';

    file_put_contents($root.DIRECTORY_SEPARATOR.'incoming.txt', $payload);

    File::factory()->create([

        'source' => 'local',

        'path' => 'imports/existing.txt',

        'hash' => hash('sha256', $payload),

        'imported_at' => now(),

    ]);

    $run = LibraryScanRun::factory()->create();

    (new ScanLibraryRun($run->id))->handle(app(AtlasStorage::class), app(LibraryScanService::class));

    expect(File::query()->count())->toBe(2)

        ->and(LibraryScanItem::query()->where('duplicate', true)->count())->toBe(0);

});

it('uses discovered scan items as the stable scan total before importing', function () {

    Queue::fake([ProcessLibraryScanItem::class]);

    $root = configureLibraryScanStorage();

    file_put_contents($root.DIRECTORY_SEPARATOR.'first.txt', 'first payload');

    file_put_contents($root.DIRECTORY_SEPARATOR.'second.txt', 'second payload');

    $run = LibraryScanRun::factory()->create();

    (new ScanLibraryRun($run->id))->handle(app(AtlasStorage::class), app(LibraryScanService::class));

    $run = $run->fresh();

    expect($run->files_found)->toBe(2)

        ->and($run->files_imported)->toBe(2)

        ->and(LibraryScanItem::query()->count())->toBe(2)

        ->and(LibraryScanItem::query()->whereNotNull('imported_path')->count())->toBe(2);

});

it('dispatches discovered files as separate import jobs', function () {

    Queue::fake([ImportLibraryScanItem::class]);

    $root = configureLibraryScanStorage();

    file_put_contents($root.DIRECTORY_SEPARATOR.'first.txt', 'first payload');

    file_put_contents($root.DIRECTORY_SEPARATOR.'second.txt', 'second payload');

    $run = LibraryScanRun::factory()->create();

    (new ScanLibraryRun($run->id))->handle(app(AtlasStorage::class), app(LibraryScanService::class));

    expect($run->fresh()->files_found)->toBe(2)

        ->and($run->fresh()->status)->toBe('processing')

        ->and(File::query()->count())->toBe(0);

    Queue::assertPushed(ImportLibraryScanItem::class, 2);

});

it('continues importing from discovered pending scan items without rediscovering', function () {

    Queue::fake([ProcessLibraryScanItem::class]);

    $root = configureLibraryScanStorage();

    $firstPath = $root.DIRECTORY_SEPARATOR.'first.txt';

    $secondPath = $root.DIRECTORY_SEPARATOR.'second.txt';

    file_put_contents($firstPath, 'first payload');

    file_put_contents($secondPath, 'second payload');

    $run = LibraryScanRun::factory()->create([

        'status' => 'processing',

        'phase' => 'processing',

        'files_found' => 2,

        'scan_completed_at' => now(),

    ]);

    LibraryScanItem::query()->create([

        'library_scan_run_id' => $run->id,

        'original_path' => $firstPath,

        'status' => LibraryScanItemStatus::PENDING,

        'phase' => 'discovered',

    ]);

    LibraryScanItem::query()->create([

        'library_scan_run_id' => $run->id,

        'original_path' => $secondPath,

        'status' => LibraryScanItemStatus::PENDING,

        'phase' => 'discovered',

    ]);

    (new ScanLibraryRun($run->id))->handle(app(AtlasStorage::class), app(LibraryScanService::class));

    $run = $run->fresh();

    expect($run->files_found)->toBe(2)

        ->and($run->files_imported)->toBe(2)

        ->and(File::query()->count())->toBe(2)

        ->and(LibraryScanItem::query()->count())->toBe(2)

        ->and(LibraryScanItem::query()->whereNotNull('imported_path')->count())->toBe(2);

});

it('continues an import item that was already moved before a retry', function () {

    Queue::fake([ProcessLibraryScanItem::class]);

    $root = configureLibraryScanStorage();

    $relativePath = 'legacy.txt';

    $importedPath = 'imports/aa/bb/retry.txt';

    Storage::disk(AtlasStorage::DISK)->put($importedPath, 'retry payload');

    $run = LibraryScanRun::factory()->create([

        'status' => 'processing',

        'phase' => 'processing',

        'files_found' => 1,

        'scan_completed_at' => now(),

    ]);

    $item = LibraryScanItem::query()->create([

        'library_scan_run_id' => $run->id,

        'original_path' => $root.DIRECTORY_SEPARATOR.$relativePath,

        'imported_path' => $importedPath,

        'status' => LibraryScanItemStatus::IMPORTING,

        'phase' => 'moving',

    ]);

    (new ImportLibraryScanItem($item->id))->handle(

        app(AtlasStorage::class),

        app(LibraryScanItemImporter::class),

        app(LibraryScanService::class),

    );

    expect(File::query()->count())->toBe(1)

        ->and($item->fresh()->status)->toBe(LibraryScanItemStatus::COMPLETED)

        ->and($item->fresh()->imported_path)->toBe($importedPath)

        ->and($run->fresh()->files_processed)->toBe(1);

});

it('reconciles existing local file records by original atlas path and preserves reactions', function () {

    Queue::fake([ProcessLibraryScanItem::class]);

    $root = configureLibraryScanStorage();

    Illuminate\Support\Facades\File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'0000 - Downloads');

    $relativePath = '0000 - Downloads/legacy.txt';

    $absolutePath = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

    file_put_contents($absolutePath, 'current legacy payload');

    $existing = File::factory()->create([

        'source' => 'local',

        'path' => $relativePath,

        'filename' => 'legacy.txt',

        'ext' => 'txt',

        'size' => 1,

        'mime_type' => 'application/octet-stream',

        'hash' => str_repeat('0', 64),

        'preview_path' => 'thumbnails/legacy.jpg',

        'poster_path' => 'thumbnails/legacy-poster.jpg',

        'downloaded' => true,

        'downloaded_at' => now(),

        'imported_at' => null,

    ]);

    $user = User::factory()->create();

    Reaction::query()->create([

        'file_id' => $existing->id,

        'user_id' => $user->id,

        'type' => 'like',

    ]);

    $run = LibraryScanRun::factory()->create();

    (new ScanLibraryRun($run->id))->handle(app(AtlasStorage::class), app(LibraryScanService::class));

    $file = $existing->fresh();

    $item = LibraryScanItem::query()->first();

    expect(File::query()->count())->toBe(1)

        ->and($file->id)->toBe($existing->id)

        ->and($file->path)->toStartWith('imports/')

        ->and($file->filename)->toBe(basename($file->path))

        ->and($file->ext)->toBe('txt')

        ->and($file->size)->toBe(strlen('current legacy payload'))

        ->and($file->hash)->toBe(hash('sha256', 'current legacy payload'))

        ->and($file->preview_path)->toBeNull()

        ->and($file->poster_path)->toBeNull()

        ->and($file->downloaded)->toBeFalse()

        ->and($file->downloaded_at)->toBeNull()

        ->and($file->imported_at)->not->toBeNull()

        ->and($item->file_id)->toBe($existing->id)

        ->and($item->duplicate)->toBeFalse()

        ->and($item->imported_path)->toBe($file->path)

        ->and(Reaction::query()->where('file_id', $existing->id)->count())->toBe(1)

        ->and(file_exists($absolutePath))->toBeFalse()

        ->and(Storage::disk(AtlasStorage::DISK)->exists($file->path))->toBeTrue();

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
