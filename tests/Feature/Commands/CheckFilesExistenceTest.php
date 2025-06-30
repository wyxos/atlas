<?php

use App\Models\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // Run migrations to ensure the files table exists
    $this->artisan('migrate');

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
    $this->artisan('files:check-existence --file=' . $nonExistingFile->id)
         ->expectsOutput('Checking file existence...')
         ->expectsOutput('Processing only file with ID: ' . $nonExistingFile->id)
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
