<?php

use App\Models\Album;
use App\Models\Artist;
use App\Models\AudioMetadataProposal;
use App\Models\AudioMetadataRun;
use App\Models\File;
use App\Models\User;
use App\Services\LibraryScans\MediaProbeService;
use App\Support\AtlasStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('audio metadata can be restored from embedded file tags and overwrites canonical db state', function () {
    configureLibraryScanStorage();

    $user = User::factory()->create();
    $filePath = 'imports/audio/power-rangers-theme.mp3';
    Storage::disk(AtlasStorage::DISK)->put($filePath, 'audio bytes');

    $file = File::factory()->create([
        'source' => 'local',
        'path' => $filePath,
        'filename' => 'power-rangers-theme.mp3',
        'mime_type' => 'audio/mpeg',
        'title' => 'Wrong Applied Title',
    ]);
    $wrongArtist = Artist::factory()->create([
        'name' => 'Wrong Artist',
        'normalized_name' => 'wrong artist',
    ]);
    $wrongAlbum = Album::factory()->create([
        'name' => 'Wrong Album',
        'normalized_name' => 'wrong album',
        'release_label' => 'Wrong Label',
        'catalog_number' => 'WRONG-1',
        'barcode' => '0000000000000',
        'release_date' => '1998',
        'release_country' => 'US',
        'musicbrainz_release_id' => 'wrong-release-mbid',
        'discogs_release_id' => '999999',
    ]);
    $file->artists()->sync([$wrongArtist->id]);
    $file->albums()->sync([
        $wrongAlbum->id => [
            'track_number' => '99',
            'disc_number' => '3',
        ],
    ]);
    $file->metadata()->create([
        'payload' => [
            'title' => 'Wrong Applied Title',
            'artist' => 'Wrong Artist',
            'album' => 'Wrong Album',
            'discogs_release_id' => '999999',
            'audio' => [
                'title' => 'Wrong Applied Title',
                'artists' => ['Wrong Artist'],
                'album' => 'Wrong Album',
                'discogs_release_id' => '999999',
            ],
        ],
        'is_extracted' => true,
    ]);

    $run = AudioMetadataRun::query()->create([
        'user_id' => $user->id,
        'scope' => 'single',
        'source_filter' => 'local',
        'status' => 'completed',
        'total_files' => 1,
    ]);
    $proposal = AudioMetadataProposal::query()->create([
        'audio_metadata_run_id' => $run->id,
        'file_id' => $file->id,
        'provider' => 'discogs_release',
        'status' => 'pending',
        'confidence' => 90,
        'current_values' => [],
        'proposed_values' => ['release_date' => '1998'],
        'changes' => ['release_date' => ['current' => null, 'proposed' => '1998']],
        'evidence' => [],
    ]);

    $this->mock(MediaProbeService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('probe')
            ->once()
            ->andReturn([
                'format' => [
                    'duration' => 139.4,
                    'tags' => [
                        'title' => 'Power Rangers in Space (TV Size)',
                        'artist' => 'Ron Wasserman',
                        'album' => 'Power Rangers In Space',
                        'track' => '1/10',
                        'disc' => '1/1',
                        'label' => 'Saban Records',
                    ],
                ],
                'streams' => [],
            ]);
    });

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata/restore-from-file");

    $response->assertSuccessful()
        ->assertJsonPath('status', 'restored')
        ->assertJsonPath('values.title', 'Power Rangers in Space (TV Size)')
        ->assertJsonPath('values.artists', ['Ron Wasserman'])
        ->assertJsonPath('values.album', 'Power Rangers In Space')
        ->assertJsonPath('values.duration_seconds', 139);

    $file->refresh()->load(['artists', 'albums', 'metadata']);
    $album = $file->albums->first();

    expect($file->title)->toBe('Power Rangers in Space (TV Size)')
        ->and($file->artists->pluck('name')->all())->toBe(['Ron Wasserman'])
        ->and($album?->name)->toBe('Power Rangers In Space')
        ->and($album?->release_label)->toBe('Saban Records')
        ->and($album?->catalog_number)->toBeNull()
        ->and($album?->barcode)->toBeNull()
        ->and($album?->release_date)->toBeNull()
        ->and($album?->release_country)->toBeNull()
        ->and($album?->musicbrainz_release_id)->toBeNull()
        ->and($album?->discogs_release_id)->toBeNull()
        ->and($album?->pivot?->track_number)->toBe('1')
        ->and($album?->pivot?->disc_number)->toBe('1')
        ->and($proposal->fresh()?->status)->toBe('superseded');

    $payload = $file->metadata?->payload ?? [];
    expect(data_get($payload, 'title'))->toBe('Power Rangers in Space (TV Size)')
        ->and(data_get($payload, 'artists'))->toBe(['Ron Wasserman'])
        ->and(data_get($payload, 'album'))->toBe('Power Rangers In Space')
        ->and(data_get($payload, 'discogs_release_id'))->toBeNull()
        ->and(data_get($payload, 'audio.discogs_release_id'))->toBeNull()
        ->and(data_get($payload, 'audio_restore.source'))->toBe('file');
});
