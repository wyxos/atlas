<?php

use App\Enums\LibraryScanItemStatus;
use App\Jobs\LibraryScans\ProcessLibraryScanItem;
use App\Jobs\LibraryScans\ScanLibraryRun;
use App\Models\File;
use App\Models\LibraryScanItem;
use App\Models\LibraryScanRun;
use App\Models\User;
use App\Services\LibraryScans\LibraryScanService;
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

it('requires authentication for library scan APIs', function () {
    $this->postJson('/api/settings/library-scans')->assertUnauthorized();
});

it('starts a library scan from settings', function () {
    Queue::fake([ScanLibraryRun::class]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/settings/library-scans');

    $response->assertAccepted()
        ->assertJsonPath('run.status', 'pending');
    Queue::assertPushed(ScanLibraryRun::class);
});
