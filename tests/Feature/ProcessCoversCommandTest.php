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

    // Create metadata records
    FileMetadata::create([
        'file_id' => $file1->id,
        'payload' => [],
        'is_extracted' => true,
    ]);

    FileMetadata::create([
        'file_id' => $file2->id,
        'payload' => [],
        'is_extracted' => true,
    ]);

    FileMetadata::create([
        'file_id' => $file3->id,
        'payload' => [],
        'is_extracted' => true,
    ]);

    // Create cover records with temporary hashes (will be recalculated by command)
    $cover1 = Cover::create([
        'path' => 'cover-art/test1.jpg',
        'hash' => 'temp1', // Temporary hash, will be updated by command
    ]);

    $cover2 = Cover::create([
        'path' => 'cover-art/test2.jpg',
        'hash' => 'temp2', // Temporary hash, will be updated by command
    ]);

    $cover3 = Cover::create([
        'path' => 'cover-art/test3.jpg', // Will have same content as test1.jpg for duplicate testing
        'hash' => 'temp3', // Temporary hash, will be updated by command
    ]);

    // Associate covers with files
    $file1->covers()->syncWithoutDetaching([$cover1->id]);
    $file2->covers()->syncWithoutDetaching([$cover2->id]);
    $file3->covers()->syncWithoutDetaching([$cover3->id]);

    // Run the command
    $this->artisan('files:process-covers')
         ->expectsOutput('Starting cover processing...')
         ->expectsOutput('Found 3 files with cover art.')
         ->assertSuccessful();

    // Refresh the files to get updated associations
    $file1->refresh();
    $file2->refresh();
    $file3->refresh();

    // Assert that files have covers associated
    $this->assertCount(1, $file1->covers);
    $this->assertCount(1, $file2->covers);
    $this->assertCount(1, $file3->covers);

    // After duplicate detection:
    // - file1 should keep cover1 (the original) 
    // - file2 should keep cover2 (unique)
    // - file3 should now be associated with cover1 (duplicate of cover3 was removed)
    // So file1 and file3 should have the same cover ID
    $this->assertEquals(
        $file1->covers->first()->id,
        $file3->covers->first()->id,
        'file1 and file3 should have the same cover after duplicate detection'
    );

    // file2 should have a different cover
    $this->assertNotEquals(
        $file1->covers->first()->id,
        $file2->covers->first()->id,
        'file1 and file2 should have different covers'
    );
    
    // After processing, we should have 3 covers in the database still
    // (the command doesn't delete Cover records, only the duplicate files)
    $this->assertDatabaseCount('covers', 3);
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
        'payload' => [],
        'is_extracted' => true,
    ]);

    // Create a cover record with a non-existent path
    $cover = Cover::create([
        'hash' => md5('nonexistent'),
        'path' => 'cover-art/nonexistent.jpg',
    ]);

    // Associate the cover with the file
    $file->covers()->syncWithoutDetaching([$cover->id]);

    // Run the command
    $this->artisan('files:process-covers')
         ->expectsOutput('Starting cover processing...')
         ->expectsOutput('Found 1 files with cover art.')
         ->expectsOutput('Cover file not found: cover-art/nonexistent.jpg')
         ->assertSuccessful();

    // Assert that the cover still exists in the database
    $this->assertDatabaseCount('covers', 1);
});

test('process covers command processes only the specified file when using --file option', function () {
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

    // Create metadata records
    FileMetadata::create([
        'file_id' => $file1->id,
        'payload' => [],
        'is_extracted' => true,
    ]);

    FileMetadata::create([
        'file_id' => $file2->id,
        'payload' => [],
        'is_extracted' => true,
    ]);

    // Create cover records
    $cover1 = Cover::create([
        'hash' => md5('test cover content 1'),
        'path' => 'cover-art/test1.jpg',
    ]);

    $cover2 = Cover::create([
        'hash' => md5('test cover content 2'),
        'path' => 'cover-art/test2.jpg',
    ]);

    // Associate covers with files
    $file1->covers()->syncWithoutDetaching([$cover1->id]);
    $file2->covers()->syncWithoutDetaching([$cover2->id]);

    // Create test cover files
    Storage::disk('public')->put('cover-art/test1.jpg', 'test cover content 1');
    Storage::disk('public')->put('cover-art/test2.jpg', 'test cover content 2');

    // Run the command with --file option for file1
    $this->artisan('files:process-covers --file=' . $file1->id)
         ->expectsOutput('Starting cover processing...')
         ->expectsOutput('Processing only file with ID: ' . $file1->id)
         ->assertSuccessful();

    // Verify that only file1's cover was processed
    // The cover path should be updated to follow the pattern cover-{coverId}.{ext}
    $cover1->refresh();
    $this->assertStringContainsString('covers/cover-' . $cover1->id, $cover1->path);

    // Verify that file2's cover was not processed
    $cover2->refresh();
    $this->assertEquals('cover-art/test2.jpg', $cover2->path);
});
