<?php

use App\Models\Album;
use App\Models\AudioMetadataProposal;
use App\Models\AudioMetadataRun;
use App\Models\File;
use App\Models\User;
use App\Services\Audio\AudioMetadataProposalApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('applying an album proposal moves duplicate current album rows as one album group', function () {
    $user = User::factory()->create();
    $currentAlbumName = 'Above & Beyond - Anjunabeats 100 Cd1';
    $firstAlbum = Album::factory()->create([
        'name' => $currentAlbumName,
        'normalized_name' => 'above & beyond - anjunabeats 100 cd1',
    ]);
    $secondAlbum = Album::factory()->create([
        'name' => $currentAlbumName,
        'normalized_name' => 'above & beyond - anjunabeats 100 cd1',
    ]);

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

    $first->albums()->attach($firstAlbum->id, ['track_number' => '1']);
    $second->albums()->attach($secondAlbum->id, ['track_number' => '2']);
    $first->metadata()->create(['payload' => ['album' => $currentAlbumName]]);
    $second->metadata()->create(['payload' => ['album' => $currentAlbumName]]);

    $run = AudioMetadataRun::query()->create([
        'user_id' => $user->id,
        'scope' => 'single',
        'source_filter' => 'all',
        'status' => 'completed',
        'total_files' => 1,
        'processed_files' => 1,
        'proposal_count' => 1,
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $proposal = AudioMetadataProposal::query()->create([
        'audio_metadata_run_id' => $run->id,
        'file_id' => $first->id,
        'provider' => 'discogs',
        'status' => 'pending',
        'confidence' => 90,
        'current_values' => [
            'title' => 'Black Is The Colour',
            'album' => $currentAlbumName,
        ],
        'proposed_values' => [
            'album' => 'Anjunabeats100 Disc One',
            'discogs_release_id' => '3191676',
        ],
        'changes' => [
            'album' => [
                'current' => $currentAlbumName,
                'proposed' => 'Anjunabeats100 Disc One',
            ],
            'discogs_release_id' => [
                'current' => null,
                'proposed' => '3191676',
            ],
        ],
        'evidence' => ['source' => 'discogs_release'],
    ]);

    app(AudioMetadataProposalApplier::class)->apply($proposal, $user, ['album', 'discogs_release_id']);

    $firstAlbumAfter = $first->fresh()->albums()->first();
    $secondAlbumAfter = $second->fresh()->albums()->first();

    expect($firstAlbumAfter?->name)->toBe('Anjunabeats100 Disc One')
        ->and($secondAlbumAfter?->name)->toBe('Anjunabeats100 Disc One')
        ->and($firstAlbumAfter?->is($secondAlbumAfter))->toBeTrue()
        ->and($firstAlbumAfter?->discogs_release_id)->toBe('3191676')
        ->and($firstAlbumAfter?->pivot?->track_number)->toBe('1')
        ->and($secondAlbumAfter?->pivot?->track_number)->toBe('2')
        ->and($second->fresh()->metadata()->first()?->payload['album'] ?? null)->toBe('Anjunabeats100 Disc One')
        ->and(Album::query()
            ->where('normalized_name', 'above & beyond - anjunabeats 100 cd1')
            ->whereHas('files')
            ->exists())->toBeFalse();
});
