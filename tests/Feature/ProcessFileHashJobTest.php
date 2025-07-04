<?php

use App\Jobs\ProcessFileHash;
use App\Models\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // Run migrations to ensure the files table exists
    $this->artisan('migrate');

    // Create a fake atlas disk for testing
    Storage::fake('atlas');
});

test('job creates file record with correct hash', function () {
    // Create a test file
    $content = 'test file content for hashing';
    Storage::disk('atlas')->put('test/sample.txt', $content);
    $filePath = Storage::disk('atlas')->path('test/sample.txt');

    // Ensure no files exist initially
    expect(File::count())->toBe(0);

    // Create and execute the job
    $job = new ProcessFileHash(
        filePath: $filePath,
        relativePath: 'test/sample.txt',
        filename: 'sample.txt',
        extension: 'txt',
        size: strlen($content),
        mimeType: 'text/plain',
        title: 'sample'
    );

    $job->handle();

    // Verify file was created
    expect(File::count())->toBe(1);

    $file = File::first();
    expect($file->source)->toBe('local');
    expect($file->path)->toBe('test/sample.txt');
    expect($file->filename)->toBe('sample.txt');
    expect($file->ext)->toBe('txt');
    expect($file->size)->toBe(strlen($content));
    expect($file->mime_type)->toBe('text/plain');
    expect($file->title)->toBe('sample');
    expect($file->downloaded)->toBeTrue();
    expect($file->download_progress)->toBe(100);
    expect($file->not_found)->toBeFalse();

    // Verify hash is correct
    expect($file->hash)->not->toBeNull();
    expect(strlen($file->hash))->toBe(64); // SHA256 hash length
    expect($file->hash)->toBe(hash('sha256', $content));
});

test('job skips processing if file already exists in database', function () {
    // Create a test file
    Storage::disk('atlas')->put('test/existing.txt', 'existing content');
    $filePath = Storage::disk('atlas')->path('test/existing.txt');

    // Create existing file record
    $existingFile = File::create([
        'source' => 'local',
        'path' => 'test/existing.txt',
        'filename' => 'existing.txt',
        'ext' => 'txt',
        'size' => 100,
        'mime_type' => 'text/plain',
        'hash' => 'existing_hash',
        'title' => 'existing',
        'downloaded' => true,
        'download_progress' => 100,
        'not_found' => false,
    ]);

    expect(File::count())->toBe(1);

    // Create and execute the job
    $job = new ProcessFileHash(
        filePath: $filePath,
        relativePath: 'test/existing.txt',
        filename: 'existing.txt',
        extension: 'txt',
        size: 100,
        mimeType: 'text/plain',
        title: 'existing'
    );

    $job->handle();

    // Verify no duplicate was created
    expect(File::count())->toBe(1);

    // Verify the original file is unchanged
    $file = File::first();
    expect($file->hash)->toBe('existing_hash');
});

test('job handles files with no extension', function () {
    // Create a test file without extension
    $content = 'file without extension';
    Storage::disk('atlas')->put('no_extension_file', $content);
    $filePath = Storage::disk('atlas')->path('no_extension_file');

    // Create and execute the job
    $job = new ProcessFileHash(
        filePath: $filePath,
        relativePath: 'no_extension_file',
        filename: 'no_extension_file',
        extension: '',
        size: strlen($content),
        mimeType: 'application/octet-stream',
        title: 'no_extension_file'
    );

    $job->handle();

    // Verify file was created correctly
    expect(File::count())->toBe(1);

    $file = File::first();
    expect($file->filename)->toBe('no_extension_file');
    expect($file->ext)->toBeNull();
    expect($file->mime_type)->toBe('application/octet-stream');
    expect($file->hash)->toBe(hash('sha256', $content));
});
