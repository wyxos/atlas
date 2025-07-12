<?php

use App\Models\Cover;
use App\Models\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // Run migrations to ensure the tables exist
    $this->artisan('migrate');

    // Create a test storage disk
    Storage::fake('atlas');

    // Create test files on disk
    Storage::disk('atlas')->put('test_file_1.jpg', 'Test content 1');
    Storage::disk('atlas')->put('test_file_2.jpg', 'Test content 2');
    Storage::disk('atlas')->put('test_file_3.jpg', 'Test content 3');

    // Create Cover records with specific hashes
    Cover::factory()->create([
        'hash' => 'duplicate_hash_1',
        'path' => 'cover1.jpg',
    ]);

    Cover::factory()->create([
        'hash' => 'duplicate_hash_2',
        'path' => 'cover2.jpg',
    ]);

    // Create File records - some with matching hashes, some without
    File::factory()->create([
        'path' => 'test_file_1.jpg',
        'filename' => 'test_file_1.jpg',
        'hash' => 'duplicate_hash_1', // This should be deleted
    ]);

    File::factory()->create([
        'path' => 'test_file_2.jpg',
        'filename' => 'test_file_2.jpg',
        'hash' => 'duplicate_hash_2', // This should be deleted
    ]);

    File::factory()->create([
        'path' => 'test_file_3.jpg',
        'filename' => 'test_file_3.jpg',
        'hash' => 'unique_hash_1', // This should NOT be deleted
    ]);

    File::factory()->create([
        'path' => 'test_file_4.jpg',
        'filename' => 'test_file_4.jpg',
        'hash' => null, // This should NOT be deleted (no hash)
    ]);
});

test('command identifies and deletes files with duplicate hashes in dry run mode', function () {
    // Run the command in dry-run mode
    $this->artisan('files:cleanup-duplicates --dry-run')
        ->expectsOutput('Running in DRY RUN mode - no files will be deleted')
        ->expectsOutput('Starting cleanup of duplicate files...')
        ->assertSuccessful();

    // Verify that no files were actually deleted in dry-run mode
    expect(File::count())->toBe(4);
    expect(Storage::disk('atlas')->exists('test_file_1.jpg'))->toBeTrue();
    expect(Storage::disk('atlas')->exists('test_file_2.jpg'))->toBeTrue();
    expect(Storage::disk('atlas')->exists('test_file_3.jpg'))->toBeTrue();
});

test('command deletes files with duplicate hashes when not in dry run mode', function () {
    // Verify initial state
    expect(File::count())->toBe(4);
    expect(Storage::disk('atlas')->exists('test_file_1.jpg'))->toBeTrue();
    expect(Storage::disk('atlas')->exists('test_file_2.jpg'))->toBeTrue();

    // Run the command without dry-run
    $this->artisan('files:cleanup-duplicates')
        ->expectsOutput('Starting cleanup of duplicate files...')
        ->assertSuccessful();

    // Verify that files with duplicate hashes were deleted
    expect(File::count())->toBe(2); // Only 2 files should remain

    // Verify the correct files remain
    $remainingFiles = File::all();
    expect($remainingFiles->pluck('hash')->toArray())->toContain('unique_hash_1');
    expect($remainingFiles->pluck('hash')->toArray())->toContain(null);

    // Verify physical files were deleted
    expect(Storage::disk('atlas')->exists('test_file_1.jpg'))->toBeFalse();
    expect(Storage::disk('atlas')->exists('test_file_2.jpg'))->toBeFalse();
    expect(Storage::disk('atlas')->exists('test_file_3.jpg'))->toBeTrue(); // Should still exist
});

test('command skips files without hashes', function () {
    // Create a file without hash
    $fileWithoutHash = File::factory()->create([
        'path' => 'no_hash_file.jpg',
        'filename' => 'no_hash_file.jpg',
        'hash' => null,
    ]);

    $initialCount = File::count();

    // Run the command
    $this->artisan('files:cleanup-duplicates')
        ->assertSuccessful();

    // Verify the file without hash still exists
    expect(File::find($fileWithoutHash->id))->not->toBeNull();
});

test('command skips files with unique hashes', function () {
    // Create a file with unique hash
    $fileWithUniqueHash = File::factory()->create([
        'path' => 'unique_file.jpg',
        'filename' => 'unique_file.jpg',
        'hash' => 'totally_unique_hash',
    ]);

    // Run the command
    $this->artisan('files:cleanup-duplicates')
        ->assertSuccessful();

    // Verify the file with unique hash still exists
    expect(File::find($fileWithUniqueHash->id))->not->toBeNull();
});

test('command handles files with URL paths correctly', function () {
    // Create a file with URL path and duplicate hash
    File::factory()->create([
        'path' => 'https://example.com/remote_file.jpg',
        'filename' => 'remote_file.jpg',
        'hash' => 'duplicate_hash_1', // Matches existing cover
    ]);

    $initialCount = File::count();

    // Run the command
    $this->artisan('files:cleanup-duplicates')
        ->assertSuccessful();

    // The file should be deleted from database even though it's a URL
    // (physical file deletion should be skipped for URLs)
    expect(File::where('path', 'https://example.com/remote_file.jpg')->exists())->toBeFalse();
});

test('command respects chunk size option', function () {
    // Run the command with a small chunk size
    $this->artisan('files:cleanup-duplicates --chunk=1 --dry-run')
        ->expectsOutput('Running in DRY RUN mode - no files will be deleted')
        ->assertSuccessful();

    // The command should complete successfully regardless of chunk size
    expect(File::count())->toBe(4);
});
