<?php

use App\Models\File;
use App\Models\FileMetadata;
use App\Support\AtlasStorage;
use Database\Seeders\AudioDevelopmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('it seeds a deterministic audio development library', function () {
    Storage::fake(AtlasStorage::DISK);

    (new AudioDevelopmentSeeder)->run();
    (new AudioDevelopmentSeeder)->run();

    $seededAudio = File::query()
        ->where('source_id', 'like', 'atlas-dev-audio-%')
        ->where('mime_type', 'like', 'audio/%');

    expect((clone $seededAudio)->count())->toBe(1000);
    expect((clone $seededAudio)->where('source', 'local')->count())->toBeGreaterThan(0);
    expect((clone $seededAudio)->where('source', 'spotify')->count())->toBeGreaterThan(0);
    expect(FileMetadata::query()->whereHas('file', fn ($query) => $query->where('source_id', 'like', 'atlas-dev-audio-%'))->count())->toBe(1000);

    $paths = (clone $seededAudio)
        ->select('path')
        ->distinct()
        ->pluck('path')
        ->all();

    expect($paths)->toHaveCount(2);
    foreach ($paths as $path) {
        Storage::disk(AtlasStorage::DISK)->assertExists($path);
    }

    $commonTrack = File::query()->where('source_id', 'atlas-dev-audio-0001')->firstOrFail();
    $edgeTrack = File::query()->where('source_id', 'atlas-dev-audio-0951')->firstOrFail();

    expect(File::query()->where('path', $commonTrack->path)->count())->toBe(950);
    expect(File::query()->where('path', $edgeTrack->path)->count())->toBe(50);

    $commonPayload = $commonTrack->metadata()->firstOrFail()->payload;
    $edgePayload = $edgeTrack->metadata()->firstOrFail()->payload;

    expect($commonPayload['duration_seconds'])->toBeGreaterThan(0);
    expect($commonPayload['title'])->toBe('Atlas Seed Track 0001');
    expect($edgePayload['title'])->not->toBe($commonPayload['title']);
});
