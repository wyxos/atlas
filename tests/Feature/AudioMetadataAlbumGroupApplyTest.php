<?php

use App\Models\Album;
use App\Models\AudioMetadataProposal;
use App\Models\AudioMetadataRun;
use App\Models\File;
use App\Models\User;
use App\Services\Audio\AudioMetadataProposalApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

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

test('applying an album proposal reuses an existing proposed album row with release evidence', function () {
    $user = User::factory()->create();
    $sourceAlbum = Album::factory()->create([
        'name' => 'Above & Beyond - Anjunabeats 100 Cd1',
        'normalized_name' => 'above & beyond - anjunabeats 100 cd1',
    ]);
    $targetAlbum = Album::factory()->create([
        'name' => 'Anjunabeats100 Disc One',
        'normalized_name' => 'anjunabeats100 disc one',
        'discogs_release_id' => null,
    ]);
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Black Is The Colour',
    ]);
    $file->albums()->attach($sourceAlbum->id, ['track_number' => '1']);

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
        'file_id' => $file->id,
        'provider' => 'discogs',
        'status' => 'pending',
        'confidence' => 90,
        'current_values' => [
            'album' => 'Above & Beyond - Anjunabeats 100 Cd1',
        ],
        'proposed_values' => [
            'album' => 'Anjunabeats100 Disc One',
            'discogs_release_id' => '3191676',
        ],
        'changes' => [
            'album' => [
                'current' => 'Above & Beyond - Anjunabeats 100 Cd1',
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

    $albumAfter = $file->fresh()->albums()->first();

    expect($albumAfter?->is($targetAlbum))->toBeTrue()
        ->and($albumAfter?->discogs_release_id)->toBe('3191676')
        ->and($sourceAlbum->fresh()?->name)->toBe('Above & Beyond - Anjunabeats 100 Cd1')
        ->and(Album::query()->where('normalized_name', 'anjunabeats100 disc one')->count())->toBe(1);
});

test('applying a release backed album proposal moves duplicate current album rows from hashed import paths', function () {
    $user = User::factory()->create();
    $currentAlbumName = 'Above & Beyond - Anjunadeep 01 Cd1';
    $firstAlbum = Album::factory()->create([
        'name' => $currentAlbumName,
        'normalized_name' => 'above & beyond - anjunadeep 01 cd1',
    ]);
    $secondAlbum = Album::factory()->create([
        'name' => $currentAlbumName,
        'normalized_name' => 'above & beyond - anjunadeep 01 cd1',
    ]);

    $first = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'path' => 'imports/97/73/hash-one.mp3',
        'title' => 'Nobody Seems To Care',
    ]);
    $second = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'path' => 'imports/11/22/hash-two.mp3',
        'title' => 'Need To Feel Loved',
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
        'provider' => 'acoustid_musicbrainz_discogs',
        'status' => 'pending',
        'confidence' => 96,
        'current_values' => [
            'title' => 'Nobody Seems To Care',
            'album' => $currentAlbumName,
        ],
        'proposed_values' => [
            'album' => 'Anjunadeep:01',
            'discogs_release_id' => '1651241',
            'musicbrainz_release_id' => 'b9dbbe2c-2fd9-4776-afdd-5a7304d71def',
        ],
        'changes' => [
            'album' => [
                'current' => $currentAlbumName,
                'proposed' => 'Anjunadeep:01',
            ],
            'discogs_release_id' => [
                'current' => null,
                'proposed' => '1651241',
            ],
            'musicbrainz_release_id' => [
                'current' => null,
                'proposed' => 'b9dbbe2c-2fd9-4776-afdd-5a7304d71def',
            ],
        ],
        'evidence' => [
            'source' => 'discogs_release',
            'discogs_release_id' => '1651241',
            'musicbrainz_release_id' => 'b9dbbe2c-2fd9-4776-afdd-5a7304d71def',
        ],
    ]);

    app(AudioMetadataProposalApplier::class)->apply($proposal, $user, ['album', 'discogs_release_id', 'musicbrainz_release_id']);

    $firstAlbumAfter = $first->fresh()->albums()->first();
    $secondAlbumAfter = $second->fresh()->albums()->first();

    expect($firstAlbumAfter?->name)->toBe('Anjunadeep:01')
        ->and($secondAlbumAfter?->name)->toBe('Anjunadeep:01')
        ->and($firstAlbumAfter?->is($secondAlbumAfter))->toBeTrue()
        ->and($firstAlbumAfter?->discogs_release_id)->toBe('1651241')
        ->and($firstAlbumAfter?->musicbrainz_release_id)->toBe('b9dbbe2c-2fd9-4776-afdd-5a7304d71def')
        ->and($firstAlbumAfter?->pivot?->track_number)->toBe('1')
        ->and($secondAlbumAfter?->pivot?->track_number)->toBe('2')
        ->and($second->fresh()->metadata()->first()?->payload['album'] ?? null)->toBe('Anjunadeep:01')
        ->and(Album::query()
            ->where('normalized_name', 'above & beyond - anjunadeep 01 cd1')
            ->whereHas('files')
            ->exists())->toBeFalse();
});

test('applying an album proposal moves duplicate mixed-by album rows', function () {
    $user = User::factory()->create();
    $currentAlbumName = 'Laser-Kissed Trance (Mixed By Above & Beyond)';
    $firstAlbum = Album::factory()->create([
        'name' => $currentAlbumName,
        'normalized_name' => 'laser-kissed trance (mixed by above & beyond)',
    ]);
    $secondAlbum = Album::factory()->create([
        'name' => $currentAlbumName,
        'normalized_name' => 'laser-kissed trance (mixed by above & beyond)',
    ]);

    $first = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'path' => 'imports/2d/ba/midnight.mp3',
        'title' => 'Midnight',
    ]);
    $second = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'path' => 'imports/cf/51/northstar.mp3',
        'title' => 'Northstar',
    ]);

    $first->albums()->attach($firstAlbum->id, ['track_number' => '2']);
    $second->albums()->attach($secondAlbum->id, ['track_number' => '5']);

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
        'provider' => 'acoustid_musicbrainz_ai_discogs',
        'status' => 'pending',
        'confidence' => 96,
        'current_values' => [
            'album' => $currentAlbumName,
        ],
        'proposed_values' => [
            'album' => 'Laser-Kissed Trance',
            'discogs_release_id' => '335178',
        ],
        'changes' => [
            'album' => [
                'current' => $currentAlbumName,
                'proposed' => 'Laser-Kissed Trance',
            ],
            'discogs_release_id' => [
                'current' => null,
                'proposed' => '335178',
            ],
        ],
        'evidence' => [
            'source' => 'acoustid_fingerprint',
            'discogs_release_id' => '335178',
        ],
    ]);

    app(AudioMetadataProposalApplier::class)->apply($proposal, $user, ['album', 'discogs_release_id']);

    $firstAlbumAfter = $first->fresh()->albums()->first();
    $secondAlbumAfter = $second->fresh()->albums()->first();

    expect($firstAlbumAfter?->name)->toBe('Laser-Kissed Trance')
        ->and($secondAlbumAfter?->name)->toBe('Laser-Kissed Trance')
        ->and($firstAlbumAfter?->is($secondAlbumAfter))->toBeTrue()
        ->and($firstAlbumAfter?->discogs_release_id)->toBe('335178')
        ->and($secondAlbumAfter?->pivot?->track_number)->toBe('5');
});

test('applying an album group proposal broadcasts every affected audio file id', function () {
    Event::fake();

    $user = User::factory()->create();
    $currentAlbumName = 'Above & Beyond - Anjunadeep 01 Cd1';
    $firstAlbum = Album::factory()->create([
        'name' => $currentAlbumName,
        'normalized_name' => 'above & beyond - anjunadeep 01 cd1',
    ]);
    $secondAlbum = Album::factory()->create([
        'name' => $currentAlbumName,
        'normalized_name' => 'above & beyond - anjunadeep 01 cd1',
    ]);

    $first = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Nobody Seems To Care',
    ]);
    $second = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Need To Feel Loved',
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
            'album' => $currentAlbumName,
        ],
        'proposed_values' => [
            'album' => 'Anjunadeep:01',
            'discogs_release_id' => '1651241',
        ],
        'changes' => [
            'album' => [
                'current' => $currentAlbumName,
                'proposed' => 'Anjunadeep:01',
            ],
            'discogs_release_id' => [
                'current' => null,
                'proposed' => '1651241',
            ],
        ],
        'evidence' => ['source' => 'discogs_release'],
    ]);

    app(AudioMetadataProposalApplier::class)->apply($proposal, $user, ['album', 'discogs_release_id']);

    Event::assertDispatched('App\\Events\\AudioFilesChanged', function (object $event) use ($first, $second, $user): bool {
        $fileIds = data_get($event, 'fileIds');

        return data_get($event, 'userId') === $user->id
            && data_get($event, 'reason') === 'metadata_applied'
            && is_array($fileIds)
            && collect($fileIds)->sort()->values()->all() === collect([$first->id, $second->id])->sort()->values()->all();
    });
});
