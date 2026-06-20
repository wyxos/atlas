<?php

use App\Jobs\LibraryScans\ProcessLibraryScanItem;
use App\Jobs\LibraryScans\ScanLibraryRun;
use App\Models\File;
use App\Models\LibraryScanRun;
use App\Services\LibraryScans\LibraryScanService;
use App\Support\AtlasStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File as Filesystem;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('removes empty imported parent directories recursively up to the atlas root', function () {
    Queue::fake([ProcessLibraryScanItem::class]);

    $root = configureLibraryScanStorage();
    $downloadsDirectory = $root.DIRECTORY_SEPARATOR.'0000 - Downloads';
    $artistDirectory = $downloadsDirectory.DIRECTORY_SEPARATOR.'Artist';
    $singlesDirectory = $artistDirectory.DIRECTORY_SEPARATOR.'Singles';
    $albumDirectory = $singlesDirectory.DIRECTORY_SEPARATOR.'Album';

    Filesystem::ensureDirectoryExists($albumDirectory);

    file_put_contents($artistDirectory.DIRECTORY_SEPARATOR.'Thumbs.db', 'thumbs');
    file_put_contents($singlesDirectory.DIRECTORY_SEPARATOR.'desktop.ini', 'desktop');
    file_put_contents($albumDirectory.DIRECTORY_SEPARATOR.'sample.txt', 'payload');

    $run = LibraryScanRun::factory()->create();

    (new ScanLibraryRun($run->id))->handle(app(AtlasStorage::class), app(LibraryScanService::class));

    expect(File::query()->count())->toBe(1)
        ->and(file_exists($albumDirectory.DIRECTORY_SEPARATOR.'sample.txt'))->toBeFalse()
        ->and(is_dir($albumDirectory))->toBeFalse()
        ->and(is_dir($singlesDirectory))->toBeFalse()
        ->and(is_dir($artistDirectory))->toBeFalse()
        ->and(is_dir($downloadsDirectory))->toBeFalse()
        ->and(is_dir($root))->toBeTrue();
});
