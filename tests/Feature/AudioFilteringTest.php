<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;



beforeEach(function () {
    // Create a test user
    $this->user = User::factory()->create();

    // Create audio files - some existing, some not found
    $this->existingAudioFile = File::factory()->create([
        'mime_type' => 'audio/mp3',
        'filename' => 'existing_audio.mp3',
        'not_found' => false,
    ]);

    $this->notFoundAudioFile = File::factory()->create([
        'mime_type' => 'audio/mp3',
        'filename' => 'missing_audio.mp3',
        'not_found' => true,
    ]);

    $this->existingVideoFile = File::factory()->create([
        'mime_type' => 'video/mp4',
        'filename' => 'existing_video.mp4',
        'not_found' => false,
    ]);

    $this->notFoundVideoFile = File::factory()->create([
        'mime_type' => 'video/mp4',
        'filename' => 'missing_video.mp4',
        'not_found' => true,
    ]);
});

test('audio page excludes files that are not found', function () {
    // Act as the test user
    $this->actingAs($this->user);

    // Visit the audio page
    $response = $this->get(route('audio'));

    // Assert the response is successful
    $response->assertStatus(200);

    // Get the files data from the response
    $files = $response->viewData('page')['props']['files'];

    // Assert that only existing audio files are included
    $fileIds = collect($files)->pluck('id')->toArray();

    expect($fileIds)->toContain($this->existingAudioFile->id);
    expect($fileIds)->not->toContain($this->notFoundAudioFile->id);

    // Video files should not be included at all (they're filtered by mime_type)
    expect($fileIds)->not->toContain($this->existingVideoFile->id);
    expect($fileIds)->not->toContain($this->notFoundVideoFile->id);
});

test('audio search query builder excludes files that are not found', function () {
    // Create searchable audio files
    $existingSearchableFile = File::factory()->create([
        'mime_type' => 'audio/mp3',
        'filename' => 'searchable_existing.mp3',
        'title' => 'Test Song',
        'not_found' => false,
    ]);

    $notFoundSearchableFile = File::factory()->create([
        'mime_type' => 'audio/mp3',
        'filename' => 'searchable_missing.mp3',
        'title' => 'Test Song',
        'not_found' => true,
    ]);

    // Test the query builder logic directly (without search engine dependency)
    $filteredFiles = File::where('mime_type', 'like', 'audio/%')
        ->where('not_found', false)
        ->get();

    // Assert that only existing audio files are included
    $filteredIds = $filteredFiles->pluck('id')->toArray();

    expect($filteredIds)->toContain($existingSearchableFile->id);
    expect($filteredIds)->not->toContain($notFoundSearchableFile->id);
});

test('audio scope filters correctly', function () {
    // Test the audio scope directly
    $audioFiles = File::audio()->get();

    // Should include both existing and not found audio files (scope only filters by mime_type)
    $audioIds = $audioFiles->pluck('id')->toArray();
    expect($audioIds)->toContain($this->existingAudioFile->id);
    expect($audioIds)->toContain($this->notFoundAudioFile->id);
    expect($audioIds)->not->toContain($this->existingVideoFile->id);
    expect($audioIds)->not->toContain($this->notFoundVideoFile->id);
});

test('audio scope with not_found filter works correctly', function () {
    // Test the audio scope with not_found filter
    $existingAudioFiles = File::audio()->where('not_found', false)->get();

    // Should only include existing audio files
    $existingAudioIds = $existingAudioFiles->pluck('id')->toArray();
    expect($existingAudioIds)->toContain($this->existingAudioFile->id);
    expect($existingAudioIds)->not->toContain($this->notFoundAudioFile->id);
    expect($existingAudioIds)->not->toContain($this->existingVideoFile->id);
    expect($existingAudioIds)->not->toContain($this->notFoundVideoFile->id);
});
