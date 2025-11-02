<?php

use App\Jobs\DownloadCoverJob;
use App\Models\Album;
use App\Models\Cover;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('atlas_app');
});

function fakeCoverResponse(string $bytes): void
{
    Http::fake([
        'https://example.com/cover.jpg' => Http::response($bytes, 200, ['Content-Type' => 'image/jpeg']),
    ]);
}

test('download cover job stores a cover when none exists', function (): void {
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAB7GkOtAAAAFklEQVR42mNk+M9QzwAEYBxVSFUBANcMBvc1+qsAAAAASUVORK5CYII=');
    fakeCoverResponse($bytes);

    $album = Album::create(['name' => 'Example Album']);

    $job = new DownloadCoverJob('album', $album->id, 'https://example.com/cover.jpg');
    $job->handle();

    $cover = $album->covers()->first();

    expect($cover)->not()->toBeNull();
    expect($cover->hash)->toBe(sha1($bytes));
    Storage::disk('atlas_app')->assertExists($cover->path);
});

test('download cover job skips duplicate hashes belonging to other models', function (): void {
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAB7GkOtAAAAFklEQVR42mNk+M9QzwAEYBxVSFUBANcMBvc1+qsAAAAASUVORK5CYII=');
    $hash = sha1($bytes);

    $existingAlbum = Album::create(['name' => 'Existing Album']);
    $existingPath = 'covers/albums/'.$existingAlbum->id.'/cover.jpg';
    Storage::disk('atlas_app')->put($existingPath, $bytes);

    Cover::create([
        'path' => $existingPath,
        'coverable_id' => $existingAlbum->id,
        'coverable_type' => $existingAlbum->getMorphClass(),
        'hash' => $hash,
    ]);

    $album = Album::create(['name' => 'New Album']);

    fakeCoverResponse($bytes);

    $job = new DownloadCoverJob('album', $album->id, 'https://example.com/cover.jpg');
    $job->handle();

    expect($album->covers()->exists())->toBeFalse();
    Storage::disk('atlas_app')->assertMissing('covers/albums/'.$album->id.'/cover.jpg');
});
