<?php

use App\Models\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('atlas_app');
    Storage::fake('atlas');
});

it('partitions files into subdirectories', function () {
    Storage::disk('atlas_app')->put('downloads/test1.jpg', 'content1');
    Storage::disk('atlas_app')->put('downloads/test2.png', 'content2');
    Storage::disk('atlas')->put('downloads/test1.jpg', 'content1');
    Storage::disk('atlas')->put('downloads/test2.png', 'content2');

    $file1 = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/test1.jpg',
        'filename' => 'test1.jpg',
    ]);

    $file2 = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/test2.png',
        'filename' => 'test2.png',
    ]);

    Artisan::call('files:partition-downloads', ['--dispatch-now' => true]);

    $output = Artisan::output();
    expect($output)->toContain('Found 2 file(s) to process');
    expect($output)->toContain('Jobs dispatched: 2');

    $file1->refresh();
    $file2->refresh();

    // Files should be moved to subdirectories
    expect($file1->path)->toMatch('/^downloads\/[a-z0-9]{2}\/test1\.jpg$/');
    expect($file2->path)->toMatch('/^downloads\/[a-z0-9]{2}\/test2\.png$/');

    // Files should exist at new locations
    expect(Storage::disk('atlas_app')->exists($file1->path))->toBeTrue();
    expect(Storage::disk('atlas_app')->exists($file2->path))->toBeTrue();
    expect(Storage::disk('atlas')->exists($file1->path))->toBeTrue();
    expect(Storage::disk('atlas')->exists($file2->path))->toBeTrue();

    // Old locations should not exist
    expect(Storage::disk('atlas_app')->exists('downloads/test1.jpg'))->toBeFalse();
    expect(Storage::disk('atlas_app')->exists('downloads/test2.png'))->toBeFalse();
});

it('shows dry run output without making changes', function () {
    Storage::disk('atlas_app')->put('downloads/test.jpg', 'content');

    $file = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/test.jpg',
        'filename' => 'test.jpg',
    ]);

    Artisan::call('files:partition-downloads', ['--dry-run' => true]);

    $output = Artisan::output();
    expect($output)->toContain('DRY RUN MODE');
    expect($output)->toContain('Would partition');

    $file->refresh();
    expect($file->path)->toBe('downloads/test.jpg');
    expect(Storage::disk('atlas_app')->exists('downloads/test.jpg'))->toBeTrue();
});

it('skips files already in subdirectories', function () {
    Storage::disk('atlas_app')->put('downloads/ab/test.jpg', 'content');

    $file = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/ab/test.jpg',
        'filename' => 'test.jpg',
    ]);

    Artisan::call('files:partition-downloads');

    $output = Artisan::output();
    expect($output)->toContain('No files found to partition');

    $file->refresh();
    expect($file->path)->toBe('downloads/ab/test.jpg');
});

it('skips files that are not downloaded', function () {
    Storage::disk('atlas_app')->put('downloads/test.jpg', 'content');

    File::factory()->create([
        'downloaded' => false,
        'path' => 'downloads/test.jpg',
        'filename' => 'test.jpg',
    ]);

    Artisan::call('files:partition-downloads');

    $output = Artisan::output();
    expect($output)->toContain('No files found to partition');
});

it('handles files missing on disk', function () {
    $file = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/missing.jpg',
        'filename' => 'missing.jpg',
    ]);

    Artisan::call('files:partition-downloads');

    $output = Artisan::output();
    expect($output)->toContain('Missing: 1');

    $file->refresh();
    expect($file->path)->toBe('downloads/missing.jpg');
});

it('respects limit option', function () {
    Storage::disk('atlas_app')->put('downloads/test1.jpg', 'content1');
    Storage::disk('atlas_app')->put('downloads/test2.jpg', 'content2');
    Storage::disk('atlas_app')->put('downloads/test3.jpg', 'content3');

    File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/test1.jpg',
        'filename' => 'test1.jpg',
    ]);

    File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/test2.jpg',
        'filename' => 'test2.jpg',
    ]);

    File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/test3.jpg',
        'filename' => 'test3.jpg',
    ]);

    Artisan::call('files:partition-downloads', ['--limit' => 2, '--dispatch-now' => true]);

    $output = Artisan::output();
    expect($output)->toContain('Found 2 file(s) to process');
    expect($output)->toContain('Jobs dispatched: 2');
});

it('uses custom subdirectory length', function () {
    Storage::disk('atlas_app')->put('downloads/test.jpg', 'content');

    $file = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/test.jpg',
        'filename' => 'test.jpg',
    ]);

    Artisan::call('files:partition-downloads', ['--subdir-length' => 3, '--dispatch-now' => true]);

    $file->refresh();
    expect($file->path)->toMatch('/^downloads\/[a-z0-9]{3}\/test\.jpg$/');
});

it('handles files with random filenames correctly', function () {
    $randomFilename = \Illuminate\Support\Str::random(40).'.jpg';
    Storage::disk('atlas_app')->put("downloads/{$randomFilename}", 'content');

    $file = File::factory()->create([
        'downloaded' => true,
        'path' => "downloads/{$randomFilename}",
        'filename' => $randomFilename,
    ]);

    Artisan::call('files:partition-downloads', ['--dispatch-now' => true]);

    $file->refresh();
    // Should use first 2 characters of random filename
    $expectedSubdir = strtolower(substr($randomFilename, 0, 2));
    expect($file->path)->toBe("downloads/{$expectedSubdir}/{$randomFilename}");
});

it('processes files in chunks', function () {
    Storage::disk('atlas_app')->put('downloads/test1.jpg', 'content1');
    Storage::disk('atlas_app')->put('downloads/test2.jpg', 'content2');

    File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/test1.jpg',
        'filename' => 'test1.jpg',
    ]);

    File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/test2.jpg',
        'filename' => 'test2.jpg',
    ]);

    Artisan::call('files:partition-downloads', ['--chunk' => 1, '--dispatch-now' => true]);

    $output = Artisan::output();
    expect($output)->toContain('Jobs dispatched: 2');
});

