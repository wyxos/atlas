<?php

use App\Events\FileMetadataUpdated;
use App\Models\File;
use App\Models\FileMetadata;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

test('it can broadcast file metadata updated event', function () {
    Event::fake();

    $file = File::factory()->create();
    $metadata = ['test' => 'data', 'width' => 512, 'height' => 512];

    event(new FileMetadataUpdated($file->id, $metadata));

    Event::assertDispatched(FileMetadataUpdated::class, function ($event) use ($file, $metadata) {
        return $event->fileId === $file->id && $event->metadata === $metadata;
    });
});

test('it returns correct broadcast data', function () {
    $file = File::factory()->create();
    $metadata = ['test' => 'data', 'width' => 512, 'height' => 512];

    $event = new FileMetadataUpdated($file->id, $metadata);

    $broadcastData = $event->broadcastWith();

    expect($broadcastData)->toBe([
        'fileId' => $file->id,
    ]);
});

test('it broadcasts on correct channel', function () {
    $file = File::factory()->create();
    $metadata = ['test' => 'data', 'width' => 512, 'height' => 512];

    $event = new FileMetadataUpdated($file->id, $metadata);

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0]->name)->toBe('file-metadata-updated');
});

test('it works with FetchPostImages job metadata storage', function () {
    $file = File::factory()->create();
    $metadata = [
        'width' => 1024,
        'height' => 768,
        'civitai_id' => 12345,
        'civitai_stats' => ['likes' => 10],
        'data' => ['meta' => ['prompt' => 'test prompt']]
    ];

    // Create metadata using the same method as FetchPostImages job
    $fileMetadata = FileMetadata::updateOrCreate(
        ['file_id' => $file->id],
        ['payload' => $metadata] // No json_encode - let the array cast handle it
    );

    // Verify metadata was stored correctly as an array (not double-encoded)
    expect($fileMetadata->payload)->toBe($metadata);
    
    // Verify the raw database value is proper JSON
    $rawPayload = DB::table('file_metadata')
        ->where('id', $fileMetadata->id)
        ->value('payload');
    
    expect(json_decode($rawPayload, true))->toBe($metadata);
});
