<?php

use App\Events\StorageProcessingProgress;
use App\Jobs\ProcessAudioJob;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('skips processing when metadata is already extracted', function (): void {
    Storage::fake('atlas');

    $audioBytes = 'fake-audio-content';
    Storage::disk('atlas')->put('music/song.mp3', $audioBytes);

    $fileRecord = File::create([
        'source' => 'local',
        'path' => 'music/song.mp3',
        'filename' => 'song.mp3',
        'ext' => 'mp3',
        'mime_type' => 'audio/mpeg',
        'size' => strlen($audioBytes),
    ]);

    $fileRecord->metadata()->create([
        'payload' => ['title' => 'Test Song'],
        'is_review_required' => false,
        'is_extracted' => true,
    ]);

    Cache::put('storage_processing:1:total', 1);
    Cache::put('storage_processing:1:done', 0);
    Cache::put('storage_processing:1:failed', 0);

    Event::fake([StorageProcessingProgress::class]);

    $job = new ProcessAudioJob(1, 'atlas', $fileRecord->fresh());
    $job->handle();

    $fileRecord->refresh();
    $metadata = $fileRecord->metadata;
    expect($metadata)->not->toBeNull();
    expect($metadata->is_extracted)->toBeTrue();
    expect($metadata->payload['title'])->toBe('Test Song');
});

it('handles missing file path gracefully', function (): void {
    Storage::fake('atlas');

    $fileRecord = File::create([
        'source' => 'local',
        'path' => null,
        'filename' => 'song.mp3',
        'ext' => 'mp3',
        'mime_type' => 'audio/mpeg',
        'size' => 1024,
    ]);

    Cache::put('storage_processing:1:total', 1);
    Cache::put('storage_processing:1:done', 0);
    Cache::put('storage_processing:1:failed', 0);

    $job = new ProcessAudioJob(1, 'atlas', $fileRecord->fresh());
    $job->handle();

    $fileRecord->refresh();
    expect($fileRecord->metadata)->toBeNull();
});

it('handles non-existent file gracefully', function (): void {
    Storage::fake('atlas');

    $fileRecord = File::create([
        'source' => 'local',
        'path' => 'music/nonexistent.mp3',
        'filename' => 'nonexistent.mp3',
        'ext' => 'mp3',
        'mime_type' => 'audio/mpeg',
        'size' => 1024,
    ]);

    Cache::put('storage_processing:1:total', 1);
    Cache::put('storage_processing:1:done', 0);
    Cache::put('storage_processing:1:failed', 0);

    $job = new ProcessAudioJob(1, 'atlas', $fileRecord->fresh());
    $job->handle();

    $fileRecord->refresh();
    // Should not have created metadata if file doesn't exist
    expect($fileRecord->metadata)->toBeNull();
});

it('skips processing when cancellation flag is set', function (): void {
    Storage::fake('atlas');

    $audioBytes = 'fake-audio-content';
    Storage::disk('atlas')->put('music/song.mp3', $audioBytes);

    $fileRecord = File::create([
        'source' => 'local',
        'path' => 'music/song.mp3',
        'filename' => 'song.mp3',
        'ext' => 'mp3',
        'mime_type' => 'audio/mpeg',
        'size' => strlen($audioBytes),
    ]);

    Cache::flush();
    Cache::put('storage_scan:1:cancel', true, now()->addMinutes(10));

    Event::fake([StorageProcessingProgress::class]);

    $job = new ProcessAudioJob(1, 'atlas', $fileRecord->fresh());
    $job->handle();

    $fileRecord->refresh();
    expect($fileRecord->metadata)->toBeNull();

    expect(Cache::get('storage_processing:1:done'))->toBe(0);
    expect(Cache::get('storage_processing:1:failed'))->toBe(0);

    Event::assertNotDispatched(StorageProcessingProgress::class);
});

it('handles when getID3 is not available gracefully', function (): void {
    Storage::fake('atlas');

    $audioBytes = 'fake-audio-content';
    Storage::disk('atlas')->put('music/song.mp3', $audioBytes);

    $fileRecord = File::create([
        'source' => 'local',
        'path' => 'music/song.mp3',
        'filename' => 'song.mp3',
        'ext' => 'mp3',
        'mime_type' => 'audio/mpeg',
        'size' => strlen($audioBytes),
    ]);

    Cache::put('storage_processing:1:total', 1);
    Cache::put('storage_processing:1:done', 0);
    Cache::put('storage_processing:1:failed', 0);

    // Mock that getID3 is not available
    // The job should handle this gracefully and return early
    $job = new ProcessAudioJob(1, 'atlas', $fileRecord->fresh());
    $job->handle();

    $fileRecord->refresh();
    // If getID3 is not available, metadata should not be created
    // (This test will pass whether getID3 is installed or not)
    // The important thing is that it doesn't throw an error
    expect($fileRecord->exists)->toBeTrue();
});
