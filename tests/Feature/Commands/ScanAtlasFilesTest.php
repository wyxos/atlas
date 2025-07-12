<?php

use App\Jobs\ProcessFileHash;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a fake atlas disk for testing
    Storage::fake('atlas');

    // Create test files in the atlas disk
    Storage::disk('atlas')->put('audio/test_song.mp3', 'fake mp3 content');
    Storage::disk('atlas')->put('video/test_video.mp4', 'fake mp4 content');
    Storage::disk('atlas')->put('images/test_image.jpg', 'fake jpg content');
    Storage::disk('atlas')->put('documents/test_doc.pdf', 'fake pdf content');
    Storage::disk('atlas')->put('subfolder/nested/deep_file.txt', 'nested file content');
});

test('command scans atlas disk and dispatches file processing jobs', function () {
    Queue::fake();

    // Ensure no files exist initially
    expect(File::count())->toBe(0);

    // Run the command
    $this->artisan('atlas:scan-files')
        ->expectsOutput('Scanning atlas disk for files...')
        ->expectsOutput('Found 5 files to process.')
        ->assertSuccessful();

    // Verify that 5 ProcessFileHash jobs were dispatched
    Queue::assertPushed(ProcessFileHash::class, 5);

    // Verify no files were created yet (since jobs are queued)
    expect(File::count())->toBe(0);
});

test('command processes files correctly when jobs are executed', function () {
    // Don't fake the queue so jobs run synchronously

    // Ensure no files exist initially
    expect(File::count())->toBe(0);

    // Run the command
    $this->artisan('atlas:scan-files')
        ->expectsOutput('Scanning atlas disk for files...')
        ->expectsOutput('Found 5 files to process.')
        ->assertSuccessful();

    // Verify files were created in database
    expect(File::count())->toBe(5);

    // Check specific file properties
    $mp3File = File::where('filename', 'test_song.mp3')->first();
    expect($mp3File)->not->toBeNull();
    expect($mp3File->source)->toBe('local');
    expect($mp3File->ext)->toBe('mp3');
    expect($mp3File->mime_type)->toBe('audio/mpeg');
    expect($mp3File->path)->toBe('audio/test_song.mp3');
    expect($mp3File->title)->toBe('test_song');
    expect($mp3File->downloaded)->toBeTrue();
    expect($mp3File->download_progress)->toBe(100);
    expect($mp3File->not_found)->toBeFalse();

    $mp4File = File::where('filename', 'test_video.mp4')->first();
    expect($mp4File)->not->toBeNull();
    expect($mp4File->source)->toBe('local');
    expect($mp4File->ext)->toBe('mp4');
    expect($mp4File->mime_type)->toBe('video/mp4');
    expect($mp4File->path)->toBe('video/test_video.mp4');

    $nestedFile = File::where('filename', 'deep_file.txt')->first();
    expect($nestedFile)->not->toBeNull();
    expect($nestedFile->path)->toBe('subfolder/nested/deep_file.txt');
    expect($nestedFile->mime_type)->toBe('text/plain');
});

test('command does not dispatch jobs for existing files', function () {
    Queue::fake();

    // Create an existing file record
    File::create([
        'source' => 'local',
        'path' => 'audio/test_song.mp3',
        'filename' => 'test_song.mp3',
        'ext' => 'mp3',
        'mime_type' => 'audio/mpeg',
        'title' => 'test_song',
        'downloaded' => true,
        'download_progress' => 100,
        'not_found' => false,
    ]);

    expect(File::count())->toBe(1);

    // Run the command
    $this->artisan('atlas:scan-files')
        ->assertSuccessful();

    // Should dispatch 4 jobs (5 total - 1 existing)
    Queue::assertPushed(ProcessFileHash::class, 4);
});

test('command handles existing files correctly when jobs are executed', function () {
    // Create an existing file record
    File::create([
        'source' => 'local',
        'path' => 'audio/test_song.mp3',
        'filename' => 'test_song.mp3',
        'ext' => 'mp3',
        'mime_type' => 'audio/mpeg',
        'title' => 'test_song',
        'downloaded' => true,
        'download_progress' => 100,
        'not_found' => false,
    ]);

    expect(File::count())->toBe(1);

    // Run the command
    $this->artisan('atlas:scan-files')
        ->assertSuccessful();

    // Should create 4 new files (5 total - 1 existing)
    expect(File::count())->toBe(5);

    // Verify the existing file wasn't duplicated
    $mp3Files = File::where('filename', 'test_song.mp3')->get();
    expect($mp3Files->count())->toBe(1);
});

test('command dry-run mode shows what would be done without dispatching jobs', function () {
    Queue::fake();

    // Ensure no files exist initially
    expect(File::count())->toBe(0);

    // Run the command in dry-run mode
    $this->artisan('atlas:scan-files --dry-run')
        ->expectsOutput('DRY RUN MODE - No changes will be made to the database')
        ->expectsOutput('Found 5 files to process.')
        ->expectsOutput('- New files would be created: 5')
        ->assertSuccessful();

    // Verify no jobs were dispatched
    Queue::assertNothingPushed();

    // Verify no files were actually created
    expect(File::count())->toBe(0);
});

test('command handles empty atlas directory gracefully', function () {
    Queue::fake();

    // Create a fresh fake atlas disk without any files
    Storage::fake('atlas');

    // Run the command
    $this->artisan('atlas:scan-files')
        ->expectsOutput('No files found in atlas directory.')
        ->assertSuccessful();

    // Verify no jobs were dispatched
    Queue::assertNothingPushed();

    // Verify no files were created
    expect(File::count())->toBe(0);
});

test('command processes files in chunks and dispatches jobs', function () {
    Queue::fake();

    // Run the command with a small chunk size
    $this->artisan('atlas:scan-files --chunk=2')
        ->expectsOutput('Found 5 files to process.')
        ->assertSuccessful();

    // Verify all 5 jobs were still dispatched
    Queue::assertPushed(ProcessFileHash::class, 5);
});

test('command processes files in chunks correctly when jobs are executed', function () {
    // Run the command with a small chunk size
    $this->artisan('atlas:scan-files --chunk=2')
        ->expectsOutput('Found 5 files to process.')
        ->assertSuccessful();

    // Verify all files were still processed
    expect(File::count())->toBe(5);
});

test('command populates file hash correctly when jobs are executed', function () {
    // Run the command
    $this->artisan('atlas:scan-files')
        ->assertSuccessful();

    // Check that files have hashes
    $files = File::all();
    foreach ($files as $file) {
        expect($file->hash)->not->toBeNull();
        expect(strlen($file->hash))->toBe(64); // SHA256 hash length
    }
});

test('command handles files without extensions when jobs are executed', function () {
    // Create a file without extension
    Storage::disk('atlas')->put('no_extension_file', 'content without extension');

    // Run the command
    $this->artisan('atlas:scan-files')
        ->assertSuccessful();

    // Check the file without extension
    $file = File::where('filename', 'no_extension_file')->first();
    expect($file)->not->toBeNull();
    expect($file->ext)->toBeNull();
    expect($file->mime_type)->toBe('application/octet-stream');
});

test('command excludes files in covers and metadata folders when jobs are executed', function () {
    // Create files in excluded folders
    Storage::disk('atlas')->put('covers/album_cover.jpg', 'album cover content');
    Storage::disk('atlas')->put('covers/subfolder/another_cover.png', 'nested cover content');
    Storage::disk('atlas')->put('metadata/track_info.json', 'metadata content');
    Storage::disk('atlas')->put('metadata/nested/deep_metadata.xml', 'nested metadata content');

    // Create a regular file that should be included
    Storage::disk('atlas')->put('music/regular_song.mp3', 'regular music content');

    // Run the command
    $this->artisan('atlas:scan-files')
        ->expectsOutput('Found 6 files to process.') // 5 original + 1 new regular file
        ->assertSuccessful();

    // Verify that only the regular files were processed (5 original + 1 new = 6 total)
    expect(File::count())->toBe(6);

    // Verify that files in excluded folders were not created
    expect(File::where('path', 'covers/album_cover.jpg')->exists())->toBeFalse();
    expect(File::where('path', 'covers/subfolder/another_cover.png')->exists())->toBeFalse();
    expect(File::where('path', 'metadata/track_info.json')->exists())->toBeFalse();
    expect(File::where('path', 'metadata/nested/deep_metadata.xml')->exists())->toBeFalse();

    // Verify that the regular file was created
    expect(File::where('path', 'music/regular_song.mp3')->exists())->toBeTrue();
});
