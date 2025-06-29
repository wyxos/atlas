<?php

use App\Models\Cover;
use App\Models\File;
use App\Models\FileMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a fake storage disk for testing
    Storage::fake('public');

    // Create test cover files
    $testCoverContent1 = 'test cover content 1';
    $testCoverContent2 = 'test cover content 2'; // Different content
    $testCoverContent3 = 'test cover content 1'; // Same as first for duplicate testing

    Storage::disk('public')->put('cover-art/test1.jpg', $testCoverContent1);
    Storage::disk('public')->put('cover-art/test2.jpg', $testCoverContent2);
    Storage::disk('public')->put('cover-art/test3.jpg', $testCoverContent3);
});

test('process covers command processes covers correctly', function () {
    // Create test files with metadata containing cover paths
    $file1 = File::factory()->create([
        'path' => 'test/file1.mp3',
        'filename' => 'file1',
        'ext' => 'mp3',
    ]);

    $file2 = File::factory()->create([
        'path' => 'test/file2.mp3',
        'filename' => 'file2',
        'ext' => 'mp3',
    ]);

    $file3 = File::factory()->create([
        'path' => 'test/file3.mp3',
        'filename' => 'file3',
        'ext' => 'mp3',
    ]);

    // Create metadata with cover paths
    FileMetadata::create([
        'file_id' => $file1->id,
        'payload' => ['cover_art_path' => 'cover-art/test1.jpg'],
        'is_extracted' => true,
    ]);

    FileMetadata::create([
        'file_id' => $file2->id,
        'payload' => ['cover_art_path' => 'cover-art/test2.jpg'],
        'is_extracted' => true,
    ]);

    FileMetadata::create([
        'file_id' => $file3->id,
        'payload' => ['cover_art_path' => 'cover-art/test3.jpg'],
        'is_extracted' => true,
    ]);

    // Run the command
    $this->artisan('files:process-covers')
         ->expectsOutput('Starting cover processing...')
         ->expectsOutput('Found 3 files with cover art.')
         ->assertSuccessful();

    // Assert that covers were created
    $this->assertDatabaseCount('covers', 2); // Only 2 because test1 and test3 have the same content

    // Get the covers
    $covers = Cover::all();

    // Assert that the cover files were renamed correctly
    foreach ($covers as $cover) {
        Storage::disk('public')->assertExists($cover->path);
        $this->assertStringStartsWith('covers/cover-', $cover->path);
    }

    // Assert that the original files were deleted
    Storage::disk('public')->assertMissing('cover-art/test1.jpg');
    Storage::disk('public')->assertMissing('cover-art/test2.jpg');
    Storage::disk('public')->assertMissing('cover-art/test3.jpg');

    // Assert that the metadata was updated
    $file1->refresh();
    $file2->refresh();
    $file3->refresh();

    // Assert that files have covers associated
    $this->assertCount(1, $file1->covers);
    $this->assertCount(1, $file2->covers);
    $this->assertCount(1, $file3->covers);

    // Assert that file1 and file3 have the same cover (duplicate detection)
    $this->assertEquals(
        $file1->metadata->payload['cover_art_path'],
        $file3->metadata->payload['cover_art_path']
    );
});

test('process covers command handles missing cover files gracefully', function () {
    // Create a test file with metadata containing a non-existent cover path
    $file = File::factory()->create([
        'path' => 'test/file4.mp3',
        'filename' => 'file4',
        'ext' => 'mp3',
    ]);

    FileMetadata::create([
        'file_id' => $file->id,
        'payload' => ['cover_art_path' => 'cover-art/nonexistent.jpg'],
        'is_extracted' => true,
    ]);

    // Run the command
    $this->artisan('files:process-covers')
         ->expectsOutput('Starting cover processing...')
         ->expectsOutput('Found 1 files with cover art.')
         ->expectsOutput('Cover file not found: cover-art/nonexistent.jpg')
         ->assertSuccessful();

    // Assert that no covers were created
    $this->assertDatabaseCount('covers', 0);
});
