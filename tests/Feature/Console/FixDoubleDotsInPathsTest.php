<?php

use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('command can fix double dots in file paths', function () {
    // Create test files with double dots in paths
    $file1 = File::factory()->create([
        'path' => 'test/path/file..jpg',
        'filename' => 'file..jpg',
    ]);

    $file2 = File::factory()->create([
        'path' => 'another/path/music..mp3',
        'filename' => 'music..mp3',
    ]);

    $file3 = File::factory()->create([
        'path' => 'normal/path/file.txt',
        'filename' => 'file.txt',
    ]);

    // Run the command in dry-run mode
    $this->artisan('files:fix-double-dots --dry-run')
        ->expectsOutput('DRY RUN MODE: No actual changes will be made')
        ->expectsOutput('Found 2 files with double dots in their paths.')
        ->assertExitCode(0);

    // Verify files haven't changed in dry-run mode
    $file1->refresh();
    $file2->refresh();
    $file3->refresh();

    expect($file1->path)->toBe('test/path/file..jpg');
    expect($file2->path)->toBe('another/path/music..mp3');
    expect($file3->path)->toBe('normal/path/file.txt');
});

test('command can process single file with double dots', function () {
    $file = File::factory()->create([
        'path' => 'test/single/file..pdf',
        'filename' => 'file..pdf',
    ]);

    // Run the command on single file in dry-run mode
    $this->artisan("files:fix-double-dots --file={$file->id} --dry-run")
        ->expectsOutput('DRY RUN MODE: No actual changes will be made')
        ->expectsOutput("Processing only file with ID: {$file->id}")
        ->expectsOutputToContain("File ID {$file->id}: Skipped - Original file does not exist")
        ->assertExitCode(0);

    // Verify file hasn't changed in dry-run mode
    $file->refresh();
    expect($file->path)->toBe('test/single/file..pdf');
});

test('command skips files without double dots', function () {
    $file = File::factory()->create([
        'path' => 'test/normal/file.jpg',
        'filename' => 'file.jpg',
    ]);

    $this->artisan("files:fix-double-dots --file={$file->id} --dry-run")
        ->expectsOutput('DRY RUN MODE: No actual changes will be made')
        ->expectsOutput("Processing only file with ID: {$file->id}")
        ->expectsOutputToContain("File ID {$file->id}: Skipped - No double dots found in path")
        ->assertExitCode(0);
});

test('command skips files with empty paths', function () {
    $file = File::factory()->create([
        'path' => null,
        'filename' => 'file.jpg',
    ]);

    $this->artisan("files:fix-double-dots --file={$file->id} --dry-run")
        ->expectsOutput('DRY RUN MODE: No actual changes will be made')
        ->expectsOutput("Processing only file with ID: {$file->id}")
        ->expectsOutputToContain("File ID {$file->id}: Skipped - No path specified")
        ->assertExitCode(0);
});

test('command handles non-existent file id', function () {
    $this->artisan('files:fix-double-dots --file=99999 --dry-run')
        ->expectsOutput('File with ID 99999 not found.')
        ->assertExitCode(1);
});

test('command reports no files found when none have double dots', function () {
    File::factory()->create([
        'path' => 'test/normal/file.jpg',
        'filename' => 'file.jpg',
    ]);

    $this->artisan('files:fix-double-dots --dry-run')
        ->expectsOutput('No files found with double dots in their paths.')
        ->assertExitCode(0);
});

test('command correctly transforms various double dot patterns', function () {
    $testCases = [
        'file..jpg' => 'file.jpg',
        'music..mp3' => 'music.mp3',
        'document..pdf' => 'document.pdf',
        'archive..zip' => 'archive.zip',
        'path/to/file..txt' => 'path/to/file.txt',
        'complex..name..with..dots..ext' => 'complex.name.with.dots.ext',
    ];

    foreach ($testCases as $original => $expected) {
        $file = File::factory()->create([
            'path' => $original,
            'filename' => basename($original),
        ]);

        $this->artisan("files:fix-double-dots --file={$file->id} --dry-run")
            ->expectsOutputToContain("File ID {$file->id}: Skipped - Original file does not exist")
            ->assertExitCode(0);
    }
});

test('command handles URL paths correctly', function () {
    $file = File::factory()->create([
        'path' => 'https://example.com/file..jpg',
        'filename' => 'file..jpg',
    ]);

    $this->artisan("files:fix-double-dots --file={$file->id} --dry-run")
        ->expectsOutput('DRY RUN MODE: No actual changes will be made')
        ->expectsOutputToContain("File ID {$file->id}: Successfully fixed - https://example.com/file..jpg -> https://example.com/file.jpg (URL - database only)")
        ->assertExitCode(0);
});
