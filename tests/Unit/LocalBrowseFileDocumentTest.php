<?php

use App\Models\Search\LocalBrowseFileDocument;

uses(Tests\TestCase::class);

it('indexes imported files as stored without marking them downloaded', function () {
    $file = new LocalBrowseFileDocument([
        'source' => 'local',
        'path' => 'imports/aa/bb/song.mp3',
        'downloaded' => false,
        'downloaded_at' => null,
        'imported_at' => now(),
        'mime_type' => 'audio/mpeg',
    ]);
    $file->id = 123;
    $file->exists = true;
    $file->setRelation('reactions', collect());

    $payload = $file->toSearchableArray();

    expect($payload['downloaded'])->toBeFalse()
        ->and($payload['stored'])->toBeTrue()
        ->and($payload['imported_at'])->toBeInt()
        ->and($payload['stored_at'])->toBe($payload['imported_at']);
});
