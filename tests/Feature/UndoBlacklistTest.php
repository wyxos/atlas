<?php

use App\Jobs\DownloadFile;
use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('undo last blacklist successfully unblacklists and likes item', function () {
    Queue::fake();
    
    // Create a user and authenticate
    $user = User::factory()->create();
    $this->actingAs($user);

    // Create a file and blacklist it
    $file = File::factory()->create([
        'is_blacklisted' => true,
        'blacklist_reason' => 'Test blacklist',
        'liked' => false,
    ]);

    // Call the undo blacklist endpoint
    $response = $this->postJson(route('browse.undo-blacklist'));

    // Assert response is successful
    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'file' => [
                'id' => $file->id,
                'filename' => $file->filename,
            ]
        ]);

    // Verify the file was updated correctly
    $file->refresh();
    expect($file->is_blacklisted)->toBeFalse();
    expect($file->blacklist_reason)->toBeNull();
    expect($file->liked)->toBeTrue();
    expect($file->liked_at)->not->toBeNull();
    
    // Verify download job was dispatched
    Queue::assertPushed(DownloadFile::class, function ($job) use ($file) {
        return $job->file->id === $file->id;
    });
});

test('undo blacklist returns 404 when no blacklisted items exist', function () {
    // Create a user and authenticate
    $user = User::factory()->create();
    $this->actingAs($user);

    // Ensure no blacklisted files exist
    File::factory()->create(['is_blacklisted' => false]);

    // Call the undo blacklist endpoint
    $response = $this->postJson(route('browse.undo-blacklist'));

    // Assert response indicates no blacklisted items found
    $response->assertStatus(404)
        ->assertJson([
            'success' => false,
            'message' => 'No blacklisted items found'
        ]);
});

test('undo blacklist selects most recently blacklisted item', function () {
    Queue::fake();
    
    // Create a user and authenticate
    $user = User::factory()->create();
    $this->actingAs($user);

    // Create multiple blacklisted files with different timestamps
    $olderFile = File::factory()->create([
        'is_blacklisted' => true,
        'blacklist_reason' => 'Older blacklist',
        'liked' => false,
        'updated_at' => now()->subMinutes(10),
    ]);

    $newerFile = File::factory()->create([
        'is_blacklisted' => true,
        'blacklist_reason' => 'Newer blacklist',
        'liked' => false,
        'updated_at' => now()->subMinutes(5),
    ]);

    // Call the undo blacklist endpoint
    $response = $this->postJson(route('browse.undo-blacklist'));

    // Assert response targets the newer file
    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'file' => [
                'id' => $newerFile->id,
            ]
        ]);

    // Verify only the newer file was updated
    $newerFile->refresh();
    $olderFile->refresh();
    
    expect($newerFile->is_blacklisted)->toBeFalse();
    expect($newerFile->liked)->toBeTrue();
    
    expect($olderFile->is_blacklisted)->toBeTrue(); // Should remain blacklisted
    expect($olderFile->liked)->toBeFalse(); // Should remain not liked
    
    // Verify download job was dispatched for the newer file
    Queue::assertPushed(DownloadFile::class, function ($job) use ($newerFile) {
        return $job->file->id === $newerFile->id;
    });
});
