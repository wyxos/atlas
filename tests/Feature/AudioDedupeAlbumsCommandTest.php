<?php

use App\Models\Album;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('audio album dedupe command merges a scoped duplicate group and preserves track pivots', function () {
    $firstAlbum = Album::factory()->create([
        'name' => 'Above & Beyond - Anjunabeats 100 Cd1',
        'normalized_name' => 'above & beyond - anjunabeats 100 cd1',
    ]);
    $secondAlbum = Album::factory()->create([
        'name' => 'Above & Beyond - Anjunabeats 100 Cd1',
        'normalized_name' => 'above & beyond - anjunabeats 100 cd1',
    ]);
    $first = File::factory()->create([
        'source' => 'local',
        'path' => 'imports/above-beyond/anjunabeats-100-cd1/01.mp3',
        'mime_type' => 'audio/mpeg',
    ]);
    $second = File::factory()->create([
        'source' => 'local',
        'path' => 'imports/above-beyond/anjunabeats-100-cd1/02.mp3',
        'mime_type' => 'audio/mpeg',
    ]);

    $first->albums()->attach($firstAlbum->id, ['track_number' => '1']);
    $second->albums()->attach($secondAlbum->id, ['track_number' => '2']);

    $exitCode = Artisan::call('audio:dedupe-albums', [
        '--normalized-name' => 'above & beyond - anjunabeats 100 cd1',
        '--path-prefix' => 'imports/above-beyond/anjunabeats-100-cd1',
        '--apply' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Album::query()->where('normalized_name', 'above & beyond - anjunabeats 100 cd1')->count())->toBe(1);

    $album = Album::query()->where('normalized_name', 'above & beyond - anjunabeats 100 cd1')->firstOrFail();

    expect($first->fresh()->albums()->first()?->is($album))->toBeTrue()
        ->and($second->fresh()->albums()->first()?->is($album))->toBeTrue()
        ->and($first->fresh()->albums()->first()?->pivot?->track_number)->toBe('1')
        ->and($second->fresh()->albums()->first()?->pivot?->track_number)->toBe('2')
        ->and(Artisan::output())->toContain('Merged 1 duplicate album row');
});
