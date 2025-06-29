<?php

use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // Create a fake storage disk for testing
    Storage::fake('local');

    // Create metadata directory
    Storage::makeDirectory('metadata');

    // Create test metadata files with warnings
    $testFiles = [
        'metadata/file1.json' => json_encode([
            'format' => [
                'container' => 'MPEG',
                'codec' => 'MP3'
            ],
            'quality' => [
                'warnings' => ['Test warning 1', 'Test warning 2']
            ]
        ], JSON_PRETTY_PRINT),

        'metadata/file2.json' => json_encode([
            'format' => [
                'container' => 'FLAC',
                'codec' => 'FLAC'
            ],
            'quality' => [
                'warnings' => ['Another warning']
            ]
        ], JSON_PRETTY_PRINT),

        'metadata/file3.json' => json_encode([
            'format' => [
                'container' => 'AAC',
                'codec' => 'AAC'
            ],
            // This file has no warnings
        ], JSON_PRETTY_PRINT)
    ];

    // Store the test files
    foreach ($testFiles as $path => $content) {
        Storage::put($path, $content);
    }
});

test('command removes warnings from metadata json files', function () {
    // Run the command
    $this->artisan('files:remove-metadata-warnings')
        ->expectsOutput('Starting to remove warnings from metadata JSON files...')
        ->expectsOutput('Found 3 metadata JSON files.')
        ->expectsOutput('Processed 3 files.')
        ->expectsOutput('Modified 2 files to remove warnings.')
        ->expectsOutput('Operation completed successfully.')
        ->assertExitCode(0);

    // Check that the warnings have been removed from the files
    $file1Content = json_decode(Storage::get('metadata/file1.json'), true);
    $file2Content = json_decode(Storage::get('metadata/file2.json'), true);
    $file3Content = json_decode(Storage::get('metadata/file3.json'), true);

    // File 1 should have empty warnings array
    expect($file1Content['quality']['warnings'])->toBe([]);

    // File 2 should have empty warnings array
    expect($file2Content['quality']['warnings'])->toBe([]);

    // File 3 should remain unchanged (no warnings to begin with)
    expect(isset($file3Content['quality']['warnings']))->toBeFalse();
});
