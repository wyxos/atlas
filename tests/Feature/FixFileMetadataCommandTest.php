<?php

use App\Models\File;
use App\Models\FileMetadata;
use Illuminate\Support\Facades\DB;


test('command identifies double-encoded records correctly', function () {
    $file = File::factory()->create(['source' => 'CivitAI']);
    
    // Create a properly encoded record (this should NOT be detected)
    $properMetadata = FileMetadata::create([
        'file_id' => $file->id,
        'payload' => ['width' => 512, 'height' => 512] // Array cast handles encoding
    ]);
    
    // Create a double-encoded record by directly inserting JSON string
    $doubleEncodedFile = File::factory()->create(['source' => 'CivitAI']);
    $metadata = ['width' => 1024, 'height' => 768, 'civitai_id' => 123];
    DB::table('file_metadata')->insert([
        'file_id' => $doubleEncodedFile->id,
        'payload' => json_encode(json_encode($metadata)), // Double-encoded
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    
    // Debug: Check what we actually inserted
    $insertedRecord = DB::selectOne('SELECT JSON_TYPE(payload) as json_type, payload FROM file_metadata WHERE file_id = ?', [$doubleEncodedFile->id]);
    expect($insertedRecord->json_type)->toBe('STRING', 'Double-encoded record should have JSON_TYPE of STRING');
    
    // Debug: Check if the command query finds it
    $queryResult = DB::select(
        'SELECT fm.id FROM file_metadata fm JOIN files f ON fm.file_id = f.id WHERE f.source = "CivitAI" AND JSON_TYPE(fm.payload) = "STRING"'
    );
    expect(count($queryResult))->toBe(1, 'Query should find exactly 1 double-encoded record');
    
    // Run command in dry-run mode
    $this->artisan('metadata:fix-records --dry-run')
        ->expectsOutput('Found 1 records that may need fixing.')
        ->expectsOutput('🔍 DRY RUN MODE - No changes will be made')
        ->assertExitCode(0);
});

test('command fixes double-encoded records', function () {
    $file = File::factory()->create(['source' => 'CivitAI']);
    $metadata = ['width' => 1024, 'height' => 768, 'civitai_id' => 123, 'data' => ['test' => 'value']];
    
    // Create double-encoded record
    DB::table('file_metadata')->insert([
        'file_id' => $file->id,
        'payload' => json_encode(json_encode($metadata)), // Double-encoded
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    
    // Verify it's double-encoded
    $rawPayload = DB::table('file_metadata')->where('file_id', $file->id)->value('payload');
    expect(json_decode($rawPayload, true))->toBeString(); // Should be a string (double-encoded)
    
    // Run fix command
    $this->artisan('metadata:fix-records')
        ->expectsQuestion('Do you want to attempt to fix 1 records?', 'yes')
        ->expectsOutput('✅ Fixed: 1 records')
        ->assertExitCode(0);
    
    // Verify it's now properly encoded
    $fixedPayload = DB::table('file_metadata')->where('file_id', $file->id)->value('payload');
    expect(json_decode($fixedPayload, true))->toBe($metadata); // Should be the original array
    
    // Verify Laravel model can read it correctly
    $fileMetadata = FileMetadata::where('file_id', $file->id)->first();
    expect($fileMetadata->payload)->toBe($metadata);
});

test('command skips properly encoded records', function () {
    $file = File::factory()->create(['source' => 'CivitAI']);
    
    // Create properly encoded record
    FileMetadata::create([
        'file_id' => $file->id,
        'payload' => ['width' => 512, 'height' => 512]
    ]);
    
    // Command should find no records to fix
    $this->artisan('metadata:fix-records --dry-run')
        ->expectsOutput('✅ No records found that need fixing.')
        ->assertExitCode(0);
});

test('command respects limit option', function () {
    // Create 3 double-encoded records
    for ($i = 1; $i <= 3; $i++) {
        $file = File::factory()->create(['source' => 'CivitAI']);
        DB::table('file_metadata')->insert([
            'file_id' => $file->id,
            'payload' => json_encode(json_encode(['width' => 512 * $i])),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    
    // Run with limit of 2
    $this->artisan('metadata:fix-records --limit=2')
        ->expectsQuestion('Do you want to attempt to fix 2 records?', 'yes')
        ->expectsOutput('✅ Fixed: 2 records')
        ->assertExitCode(0);
    
    // Should still have 1 unfixed record
    $this->artisan('metadata:fix-records --dry-run')
        ->expectsOutput('Found 1 records that may need fixing.')
        ->assertExitCode(0);
});
