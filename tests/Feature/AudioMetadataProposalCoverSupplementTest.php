<?php

use App\Models\Album;
use App\Models\AlbumCover;
use App\Models\Artist;
use App\Models\AudioMetadataProposal;
use App\Models\AudioMetadataRun;
use App\Models\File;
use App\Models\User;
use App\Services\Audio\AudioFingerprint;
use App\Services\Audio\AudioFingerprintService;
use App\Support\FileApiPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('fingerprint proposal keeps musicbrainz release search cover when fingerprint has no release', function () {
    config([
        'services.audio_metadata.acoustid_client_key' => 'acoustid-client',
        'services.audio_metadata.acoustid_api_base_url' => 'https://acoustid.test/v2',
        'services.audio_metadata.musicbrainz_api_base_url' => 'https://musicbrainz.test',
        'services.audio_metadata.cover_art_archive_base_url' => 'https://cover.test',
    ]);

    $this->mock(AudioFingerprintService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forFile')
            ->once()
            ->andReturn(new AudioFingerprint('chain-fingerprint', 220, '/tmp/chain.mp3'));
    });

    Http::fake([
        'https://acoustid.test/v2/lookup*' => Http::response([
            'status' => 'ok',
            'results' => [[
                'id' => 'acoustid-chain',
                'score' => 1.0,
                'recordings' => [[
                    'id' => 'chain-recording-mbid',
                    'title' => 'Chain',
                    'duration' => 219000,
                    'artists' => [
                        ['name' => 'BACK-ON'],
                    ],
                ]],
            ]],
        ]),
        'https://musicbrainz.test/ws/2/release/chain-release*' => Http::response([
            'id' => 'chain-release',
            'title' => 'Chain',
            'date' => '2006-06-07',
            'country' => 'JP',
        ]),
        'https://musicbrainz.test/ws/2/release?*' => Http::response([
            'releases' => [[
                'id' => 'chain-release',
                'score' => 100,
                'title' => 'Chain',
                'artist-credit' => [[
                    'name' => 'BACK-ON',
                    'artist' => ['name' => 'BACK-ON'],
                ]],
            ]],
        ]),
        'https://cover.test/release/chain-release' => Http::response([
            'images' => [[
                'front' => true,
                'image' => 'https://cover.test/release/chain-release/front.jpg',
                'thumbnails' => [
                    'large' => 'https://cover.test/release/chain-release/front-500.jpg',
                ],
            ]],
        ]),
    ]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Chain',
        'filename' => 'chain.mp3',
    ]);
    $artist = Artist::factory()->create([
        'name' => 'BACK-ON',
        'normalized_name' => 'back-on',
    ]);
    $album = Album::factory()->create([
        'name' => 'Chain',
        'normalized_name' => 'chain',
    ]);
    $file->artists()->sync([$artist->id]);
    $file->albums()->sync([$album->id]);
    $file->metadata()->create([
        'payload' => [
            'duration' => 220.1,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'acoustid_musicbrainz')
        ->assertJsonPath('proposal.proposed_values.cover_url', 'https://cover.test/release/chain-release/front-500.jpg')
        ->assertJsonPath('proposal.evidence.cover_source', 'cover_art_archive')
        ->assertJsonPath('proposal.evidence.musicbrainz_release_id', 'chain-release');

    expect($response->json('proposal.proposed_values'))->not->toHaveKey('musicbrainz_release_id');
});

test('cover lookup proposes an existing sibling album cover for duplicate album rows', function () {
    $this->mock(AudioFingerprintService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forFile')
            ->once()
            ->andReturn(null);
    });

    Http::fake();

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Shizuku',
        'filename' => 'shizuku.mp3',
        'preview_url' => null,
        'preview_path' => null,
        'poster_path' => null,
    ]);
    $artist = Artist::factory()->create([
        'name' => 'Miwako Okuda',
        'normalized_name' => 'miwako okuda',
    ]);
    $albumWithoutCover = Album::factory()->create([
        'name' => 'GTO TV Animation Original Soundtrack 2',
        'normalized_name' => 'gto tv animation original soundtrack 2',
    ]);
    $albumWithCover = Album::factory()->create([
        'name' => 'GTO TV Animation Original Soundtrack 2',
        'normalized_name' => 'gto tv animation original soundtrack 2',
    ]);
    $cover = AlbumCover::factory()->create([
        'album_id' => $albumWithCover->id,
        'file_id' => $file->id,
        'path' => 'imports/gto/covers/front.jpg',
        'path_hash' => hash('sha256', 'imports/gto/covers/front.jpg'),
        'is_default' => true,
    ]);

    $file->artists()->sync([$artist->id]);
    $file->albums()->sync([$albumWithoutCover->id]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'existing_album_cover')
        ->assertJsonPath('proposal.proposed_values.cover_url', FileApiPath::albumCover($cover->id))
        ->assertJsonPath('proposal.evidence.source', 'existing_album_cover')
        ->assertJsonPath('proposal.evidence.existing_album_id', $albumWithCover->id)
        ->assertJsonPath('proposal.evidence.existing_album_cover_id', $cover->id);
});

test('metadata proposal applies existing atlas album cover urls as album cover assets', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Shizuku',
        'preview_url' => null,
        'preview_path' => null,
        'poster_path' => null,
    ]);
    $targetAlbum = Album::factory()->create([
        'name' => 'GTO TV Animation Original Soundtrack 2',
        'normalized_name' => 'gto tv animation original soundtrack 2',
    ]);
    $sourceAlbum = Album::factory()->create([
        'name' => 'GTO TV Animation Original Soundtrack 2',
        'normalized_name' => 'gto tv animation original soundtrack 2',
    ]);
    $sourceCover = AlbumCover::factory()->create([
        'album_id' => $sourceAlbum->id,
        'file_id' => $file->id,
        'path' => 'imports/gto/covers/front.jpg',
        'path_hash' => hash('sha256', 'imports/gto/covers/front.jpg'),
        'hash' => 'source-cover-hash',
        'mime_type' => 'image/jpeg',
        'picture_type' => 'front',
        'sort_order' => 0,
        'is_default' => true,
    ]);
    $file->albums()->sync([$targetAlbum->id]);

    $run = AudioMetadataRun::query()->create([
        'user_id' => $user->id,
        'scope' => 'single',
        'source_filter' => 'local',
        'status' => 'completed',
        'total_files' => 1,
        'processed_files' => 1,
        'proposal_count' => 1,
        'options' => ['file_id' => $file->id],
    ]);
    $proposal = AudioMetadataProposal::query()->create([
        'audio_metadata_run_id' => $run->id,
        'file_id' => $file->id,
        'provider' => 'existing_album_cover',
        'status' => 'pending',
        'confidence' => 88,
        'current_values' => ['cover_url' => null],
        'proposed_values' => ['cover_url' => "/api/audio/album-covers/{$sourceCover->id}"],
        'changes' => [
            'cover_url' => [
                'current' => null,
                'proposed' => "/api/audio/album-covers/{$sourceCover->id}",
            ],
        ],
        'evidence' => ['source' => 'existing_album_cover'],
    ]);

    $response = $this->actingAs($user)->patchJson("/api/audio/metadata-proposals/{$proposal->id}", [
        'action' => 'apply',
        'fields' => ['cover_url'],
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('proposal.status', 'applied');

    $newCover = AlbumCover::query()
        ->where('album_id', $targetAlbum->id)
        ->where('is_default', true)
        ->first();

    expect($newCover)->not->toBeNull()
        ->and($newCover?->id)->not->toBe($sourceCover->id)
        ->and($newCover?->path)->toBe($sourceCover->path)
        ->and($newCover?->path_hash)->toBe($sourceCover->path_hash)
        ->and($newCover?->hash)->toBe($sourceCover->hash)
        ->and($file->fresh()?->preview_url)->toBeNull();
});
