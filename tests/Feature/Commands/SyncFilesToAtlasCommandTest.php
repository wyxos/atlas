<?php

use App\Console\Commands\SyncFilesToAtlasCommand;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Mock the atlas disk
    Storage::fake('atlas');
});

test('command skips files that already exist in atlas disk', function () {
    // Create a test file
    $file = File::factory()->create([
        'path' => 'test/file.txt',
        'not_found' => false,
    ]);

    // Mock that the file exists in atlas disk
    Storage::disk('atlas')->put('test/file.txt', 'test content');

    $this->artisan(SyncFilesToAtlasCommand::class, ['--file' => $file->id])
        ->expectsOutput("File ID {$file->id}: Already exists in atlas disk")
        ->assertExitCode(0);

    // Verify the file was not changed
    $file->refresh();
    expect($file->not_found)->toBeFalse();
});

test('command moves files from local path to atlas disk', function () {
    // Use a relative path that works with the test environment
    $testFilePath = 'test_file.txt';

    // Create the file in the base directory for testing
    file_put_contents($testFilePath, 'test content');

    // Create a test file record
    $file = File::factory()->create([
        'path' => $testFilePath,
        'not_found' => true, // Initially marked as not found
    ]);

    $this->artisan(SyncFilesToAtlasCommand::class, ['--file' => $file->id])
        ->expectsOutput("File ID {$file->id}: Moved to atlas disk successfully")
        ->assertExitCode(0);

    // Verify the file was moved to atlas disk
    expect(Storage::disk('atlas')->exists($testFilePath))->toBeTrue();
    expect(Storage::disk('atlas')->get($testFilePath))->toBe('test content');

    // Verify the file record was updated
    $file->refresh();
    expect($file->not_found)->toBeFalse();

    // Clean up
    if (file_exists($testFilePath)) {
        unlink($testFilePath);
    }
});

test('command flags files as not found when they do not exist locally', function () {
    // Create a test file record with non-existent path
    $file = File::factory()->create([
        'path' => '/non/existent/file.txt',
        'not_found' => false, // Initially not marked as not found
    ]);

    $this->artisan(SyncFilesToAtlasCommand::class, ['--file' => $file->id])
        ->expectsOutput("File ID {$file->id}: File not found, flagged as not_found")
        ->assertExitCode(0);

    // Verify the file was flagged as not found
    $file->refresh();
    expect($file->not_found)->toBeTrue();
});

test('command skips files without a path', function () {
    // Create a test file record without a path
    $file = File::factory()->create([
        'path' => null,
        'not_found' => false,
    ]);

    $this->artisan(SyncFilesToAtlasCommand::class, ['--file' => $file->id])
        ->expectsOutput("File ID {$file->id}: No path specified")
        ->assertExitCode(0);

    // Verify the file was not changed
    $file->refresh();
    expect($file->not_found)->toBeFalse();
});

test('command processes all files when no specific file ID is provided', function () {
    // Create multiple test files
    $file1 = File::factory()->create([
        'path' => 'test/file1.txt',
        'not_found' => false,
    ]);

    $file2 = File::factory()->create([
        'path' => '/non/existent/file2.txt',
        'not_found' => false,
    ]);

    // Mock that file1 exists in atlas disk
    Storage::disk('atlas')->put('test/file1.txt', 'test content 1');

    $this->artisan(SyncFilesToAtlasCommand::class, ['--chunk' => 10])
        ->expectsOutput('Starting file sync to atlas disk...')
        ->expectsOutput('Found 2 files to process.')
        ->expectsOutput('File sync to atlas completed:')
        ->expectsOutput('- Total files: 2')
        ->expectsOutput('- Skipped (already in atlas): 1')
        ->expectsOutput('- Moved to atlas: 0')
        ->expectsOutput('- Flagged as not found: 1')
        ->expectsOutput('- Errors: 0')
        ->assertExitCode(0);

    // Verify the results
    $file1->refresh();
    $file2->refresh();

    expect($file1->not_found)->toBeFalse(); // Should remain false (skipped)
    expect($file2->not_found)->toBeTrue();  // Should be flagged as not found
});

test('command handles non-existent file ID gracefully', function () {
    $this->artisan(SyncFilesToAtlasCommand::class, ['--file' => 99999])
        ->expectsOutput('File with ID 99999 not found.')
        ->assertExitCode(1);
});

test('command handles empty database gracefully', function () {
    $this->artisan(SyncFilesToAtlasCommand::class)
        ->expectsOutput('No files found in the database.')
        ->assertExitCode(0);
});
