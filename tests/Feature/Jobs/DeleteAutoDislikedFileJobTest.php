<?php

use App\Jobs\DeleteAutoDislikedFileJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('atlas-app');
    Storage::fake('atlas');
});

test('deletes file from atlas-app disk', function () {
    $filePath = 'downloads/ab/cd/test.jpg';
    Storage::disk('atlas-app')->put($filePath, 'test content');

    $job = new DeleteAutoDislikedFileJob($filePath);
    $job->handle();

    Storage::disk('atlas-app')->assertMissing($filePath);
});

test('deletes file from atlas disk', function () {
    $filePath = 'downloads/ab/cd/test.jpg';
    Storage::disk('atlas')->put($filePath, 'test content');

    $job = new DeleteAutoDislikedFileJob($filePath);
    $job->handle();

    Storage::disk('atlas')->assertMissing($filePath);
});

test('deletes file from both disks when present', function () {
    $filePath = 'downloads/ab/cd/test.jpg';
    Storage::disk('atlas-app')->put($filePath, 'test content');
    Storage::disk('atlas')->put($filePath, 'test content');

    $job = new DeleteAutoDislikedFileJob($filePath);
    $job->handle();

    Storage::disk('atlas-app')->assertMissing($filePath);
    Storage::disk('atlas')->assertMissing($filePath);
});

test('handles missing file gracefully', function () {
    $filePath = 'downloads/ab/cd/nonexistent.jpg';

    $job = new DeleteAutoDislikedFileJob($filePath);
    $job->handle();

    // Should not throw exception
    expect(true)->toBeTrue();
});

test('handles empty file path', function () {
    $job = new DeleteAutoDislikedFileJob('');
    $job->handle();

    // Should return early without error
    expect(true)->toBeTrue();
});

test('handles disk errors gracefully', function () {
    Log::spy();
    $filePath = 'downloads/ab/cd/test.jpg';

    // Create job with invalid disk name to trigger error
    $job = new DeleteAutoDislikedFileJob($filePath, ['invalid-disk']);
    $job->handle();

    // Should not throw exception, should log error
    Log::shouldHaveReceived('debug');
});

test('uses custom disk names when provided', function () {
    $filePath = 'downloads/ab/cd/test.jpg';
    Storage::fake('custom-disk');
    Storage::disk('custom-disk')->put($filePath, 'test content');

    $job = new DeleteAutoDislikedFileJob($filePath, ['custom-disk']);
    $job->handle();

    Storage::disk('custom-disk')->assertMissing($filePath);
});

test('continues to next disk if one fails', function () {
    Log::spy();
    $filePath = 'downloads/ab/cd/test.jpg';
    Storage::disk('atlas')->put($filePath, 'test content');

    // First disk will fail (invalid), second should succeed
    $job = new DeleteAutoDislikedFileJob($filePath, ['invalid-disk', 'atlas']);
    $job->handle();

    Storage::disk('atlas')->assertMissing($filePath);
    Log::shouldHaveReceived('debug');
});
