<?php

use App\Models\Album;
use App\Models\File;
use App\Services\Audio\AudioMetadataIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('compilation tracks from the same import album folder reuse one album row', function () {
    $first = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'path' => 'imports/above-beyond/anjunabeats-100-cd1/01-black-is-the-colour.mp3',
        'title' => 'Black Is The Colour',
    ]);
    $second = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'path' => 'imports/above-beyond/anjunabeats-100-cd1/02-helsinki-scorchin.mp3',
        'title' => 'Helsinki Scorchin',
    ]);

    $service = app(AudioMetadataIngestionService::class);

    $service->ingest($first, [
        'artist' => 'Coco & Green',
        'album' => 'Above & Beyond - Anjunabeats 100 Cd1',
    ]);
    $service->ingest($second, [
        'artist' => 'Super8 & Tab',
        'album' => 'Above & Beyond - Anjunabeats 100 Cd1',
    ]);

    expect(Album::query()->where('normalized_name', 'above & beyond - anjunabeats 100 cd1')->count())->toBe(1)
        ->and($first->fresh()->albums()->pluck('albums.name')->all())->toBe(['Above & Beyond - Anjunabeats 100 Cd1'])
        ->and($second->fresh()->albums()->pluck('albums.name')->all())->toBe(['Above & Beyond - Anjunabeats 100 Cd1'])
        ->and($first->fresh()->albums()->first()?->is($second->fresh()->albums()->first()))->toBeTrue()
        ->and($first->fresh()->artists()->pluck('name')->all())->toBe(['Coco & Green'])
        ->and($second->fresh()->artists()->pluck('name')->all())->toBe(['Super8 & Tab']);
});
