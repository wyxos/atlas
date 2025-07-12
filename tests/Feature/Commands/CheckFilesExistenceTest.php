<?php

use App\Models\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // Create a test storage disk
    Storage::fake('test_disk');

    // Create a test file that exists
    Storage::disk('test_disk')->put('existing_file.txt', 'Test content');

    // Get the full path to the existing file
    $existingFilePath = Storage::disk('test_disk')->path('existing_file.txt');

    // Create file records in the database
    File::factory()->create([
        'path' => $existingFilePath,
        'filename' => 'existing_file.txt',
        'not_found' => false, // Initially set to false
    ]);

    File::factory()->create([
        'path' => Storage::disk('test_disk')->path('non_existing_file.txt'),
        'filename' => 'non_existing_file.txt',
        'not_found' => false, // Initially set to false
    ]);

    File::factory()->create([
        'path' => 'https://example.com/some_url.txt',
        'filename' => 'some_url.txt',
        'not_found' => false, // Initially set to false
    ]);
});

test('command correctly identifies existing and non-existing files', function () {
    // Run the command
    $this->artisan('files:check-existence')
        ->expectsOutput('Checking file existence...')
        ->assertSuccessful();

    // Verify the results
    $existingFile = File::where('filename', 'existing_file.txt')->first();
    $nonExistingFile = File::where('filename', 'non_existing_file.txt')->first();
    $urlFile = File::where('filename', 'some_url.txt')->first();

    // The existing file should have not_found = false
    expect($existingFile->not_found)->toBeFalse();

    // The non-existing file should have not_found = true
    expect($nonExistingFile->not_found)->toBeTrue();

    // The URL file should have not_found = false (as we assume URLs exist)
    expect($urlFile->not_found)->toBeFalse();
});

test('command processes only the specified file when using --file option', function () {
    // Reset the not_found flags to false for all files
    File::query()->update(['not_found' => false]);

    // Get the non-existing file
    $nonExistingFile = File::where('filename', 'non_existing_file.txt')->first();

    // Run the command with the --file option for the non-existing file
    $this->artisan('files:check-existence --file='.$nonExistingFile->id)
        ->expectsOutput('Checking file existence...')
        ->expectsOutput('Processing only file with ID: '.$nonExistingFile->id)
        ->assertSuccessful();

    // Refresh the models
    $existingFile = File::where('filename', 'existing_file.txt')->first();
    $nonExistingFile->refresh();
    $urlFile = File::where('filename', 'some_url.txt')->first();

    // Only the specified file should be processed
    // The non-existing file should have not_found = true
    expect($nonExistingFile->not_found)->toBeTrue();

    // The other files should remain unchanged (not_found = false)
    expect($existingFile->not_found)->toBeFalse();
    expect($urlFile->not_found)->toBeFalse();
});

test('command finds and renames double-dot files', function () {
    // Create a file with double dots that should be renamed
    Storage::disk('test_disk')->put('test_file..jpg', 'Test image content');

    // Get the path to the double-dot file
    $doubleDotsFilePath = Storage::disk('test_disk')->path('test_file..jpg');
    $originalFilePath = Storage::disk('test_disk')->path('test_file.jpg');

    // Create a file record in the database for the original filename (without double dots)
    $fileRecord = File::factory()->create([
        'path' => $originalFilePath,
        'filename' => 'test_file.jpg',
        'not_found' => false, // Initially set to false
    ]);

    // Verify the original file doesn't exist but the double-dot version does
    expect(file_exists($originalFilePath))->toBeFalse();
    expect(file_exists($doubleDotsFilePath))->toBeTrue();

    // Run the command for this specific file
    $this->artisan('files:check-existence --file=' . $fileRecord->id)
        ->expectsOutput('Checking file existence...')
        ->assertSuccessful();

    // Refresh the file record
    $fileRecord->refresh();

    // The file should now be marked as found (not_found = false)
    expect($fileRecord->not_found)->toBeFalse();

    // The original file should now exist (renamed from double-dot version)
    expect(file_exists($originalFilePath))->toBeTrue();

    // The double-dot file should no longer exist
    expect(file_exists($doubleDotsFilePath))->toBeFalse();
});
