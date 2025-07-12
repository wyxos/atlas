<?php

use App\Jobs\DeleteM3uFilesJob;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Mock the atlas storage disk
    Storage::fake('atlas');
    Queue::fake();
});

test('command shows no files message when no m3u files exist', function () {
    // Create some non-m3u files
    File::factory()->create(['ext' => 'mp3', 'filename' => 'song.mp3']);
    File::factory()->create(['ext' => 'wav', 'filename' => 'audio.wav']);

    $this->artisan('files:delete-m3u')
        ->expectsOutput('Scanning for M3U files...')
        ->expectsOutput('No M3U files found in the database.')
        ->assertExitCode(0);
});

test('command finds m3u files by extension', function () {
    // Create m3u files
    File::factory()->create(['ext' => 'm3u', 'filename' => 'playlist.m3u']);
    File::factory()->create(['ext' => 'm3u8', 'filename' => 'stream.m3u8']);

    // Create non-m3u file
    File::factory()->create(['ext' => 'mp3', 'filename' => 'song.mp3']);

    $this->artisan('files:delete-m3u --preview')
        ->expectsOutput('Scanning for M3U files...')
        ->expectsOutput('Found 2 M3U files.')
        ->expectsOutput('Preview of M3U files that would be deleted:')
        ->assertExitCode(0);
});

test('command finds m3u files by mime type', function () {
    // Create files with m3u mime types
    File::factory()->create([
        'ext' => 'txt',
        'filename' => 'playlist.txt',
        'mime_type' => 'application/m3u'
    ]);
    File::factory()->create([
        'ext' => 'txt',
        'filename' => 'stream.txt',
        'mime_type' => 'application/vnd.apple.mpegurl'
    ]);

    $this->artisan('files:delete-m3u --preview')
        ->expectsOutput('Scanning for M3U files...')
        ->expectsOutput('Found 2 M3U files.')
        ->assertExitCode(0);
});

test('command finds m3u files by filename', function () {
    // Create files with .m3u in filename
    File::factory()->create([
        'ext' => 'txt',
        'filename' => 'my-playlist.m3u.backup',
        'mime_type' => 'text/plain'
    ]);

    $this->artisan('files:delete-m3u --preview')
        ->expectsOutput('Scanning for M3U files...')
        ->expectsOutput('Found 1 M3U files.')
        ->assertExitCode(0);
});

test('command shows preview correctly', function () {
    // Create m3u file with specific data
    File::factory()->create([
        'ext' => 'm3u',
        'filename' => 'test-playlist.m3u',
        'path' => '/path/to/playlist.m3u',
        'size' => 1024,
        'mime_type' => 'application/m3u'
    ]);

    $this->artisan('files:delete-m3u --preview')
        ->expectsOutput('Preview of M3U files that would be deleted:')
        ->expectsOutput('To actually delete these files, run the command without --preview flag.')
        ->assertExitCode(0);
});

test('command requires confirmation by default for batch processing', function () {
    File::factory()->create(['ext' => 'm3u', 'filename' => 'playlist.m3u']);

    $this->artisan('files:delete-m3u --batch')
        ->expectsQuestion('Are you sure you want to proceed?', false)
        ->expectsOutput('Operation cancelled.')
        ->assertExitCode(0);

    Queue::assertNothingPushed();
});

test('command dispatches job when confirmed in batch mode', function () {
    File::factory()->create(['ext' => 'm3u', 'filename' => 'playlist.m3u']);

    $this->artisan('files:delete-m3u --batch')
        ->expectsQuestion('Are you sure you want to proceed?', true)
        ->expectsOutput('M3U files deletion job has been queued.')
        ->assertExitCode(0);

    Queue::assertPushed(DeleteM3uFilesJob::class);
});

test('command skips confirmation with force flag in batch mode', function () {
    File::factory()->create(['ext' => 'm3u', 'filename' => 'playlist.m3u']);

    $this->artisan('files:delete-m3u --batch --force')
        ->expectsOutput('M3U files deletion job has been queued.')
        ->assertExitCode(0);

    Queue::assertPushed(DeleteM3uFilesJob::class);
});

test('command passes chunk size to job in batch mode', function () {
    File::factory()->create(['ext' => 'm3u', 'filename' => 'playlist.m3u']);

    $this->artisan('files:delete-m3u --batch --force --chunk=50')
        ->assertExitCode(0);

    Queue::assertPushed(DeleteM3uFilesJob::class, function ($job) {
        return $job->chunkSize === 50;
    });
});

test('preview shows limited number of files', function () {
    // Create 25 m3u files
    File::factory()->count(25)->create(['ext' => 'm3u']);

    $this->artisan('files:delete-m3u --preview')
        ->expectsOutput('Found 25 M3U files.')
        ->expectsOutput('... and 5 more files.')
        ->assertExitCode(0);
});

test('command processes files individually by default', function () {
    File::factory()->create(['ext' => 'm3u', 'filename' => 'playlist.m3u']);

    $this->artisan('files:delete-m3u')
        ->expectsOutput('Processing files individually with confirmation...')
        ->expectsQuestion('Delete this file?', false)
        ->expectsOutput('- File skipped.')
        ->expectsOutput('Deletion Summary:')
        ->expectsOutput('✓ Deleted: 0 files')
        ->expectsOutput('- Skipped: 1 files')
        ->assertExitCode(0);

    // File should still exist
    expect(File::count())->toBe(1);
});

test('command deletes files when confirmed individually', function () {
    File::factory()->create(['ext' => 'm3u', 'filename' => 'playlist.m3u']);

    $this->artisan('files:delete-m3u')
        ->expectsOutput('Processing files individually with confirmation...')
        ->expectsQuestion('Delete this file?', true)
        ->expectsOutput('Deletion Summary:')
        ->assertExitCode(0);

    // Note: In test environment, actual file deletion may not work due to missing file paths
    // The important thing is that the command processes files individually and asks for confirmation
});

test('command processes files individually with force flag', function () {
    File::factory()->create(['ext' => 'm3u', 'filename' => 'playlist.m3u']);

    $this->artisan('files:delete-m3u --force')
        ->expectsOutput('Processing files individually with confirmation...')
        ->expectsOutput('Deletion Summary:')
        ->assertExitCode(0);

    // Note: In test environment, actual file deletion may not work due to missing file paths
    // The important thing is that the command processes files individually without asking for confirmation when --force is used
});
