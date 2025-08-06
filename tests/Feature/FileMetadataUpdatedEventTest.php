<?php

use App\Events\FileMetadataUpdated;
use App\Models\File;
use App\Models\FileMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

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
        'metadata' => $metadata,
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
