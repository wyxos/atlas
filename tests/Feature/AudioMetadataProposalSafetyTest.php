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
use App\Support\AtlasStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('high fingerprint score without identity support does not create a proposal', function () {
    config([
        'services.audio_metadata.acoustid_client_key' => 'acoustid-client',
        'services.audio_metadata.acoustid_api_base_url' => 'https://acoustid.test/v2',
    ]);

    $this->mock(AudioFingerprintService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forFile')
            ->once()
            ->andReturn(new AudioFingerprint('duration-only-fingerprint', 89, '/tmp/audio.mp3'));
    });

    Http::fake([
        'https://acoustid.test/v2/lookup*' => Http::response([
            'status' => 'ok',
            'results' => [[
                'id' => 'acoustid-duration-only',
                'score' => 0.973,
                'recordings' => [[
                    'id' => 'wrong-recording-mbid',
                    'title' => 'CG Project',
                    'duration' => 91000,
                    'artists' => [
                        ['name' => 'Latenighters'],
                    ],
                ]],
            ]],
        ]),
    ]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => '11 -SoulTaker (TV Edit)',
        'filename' => '11 -SoulTaker (TV Edit).mp3',
    ]);
    $album = Album::factory()->create([
        'name' => 'SoulTaker Original Soundtrack',
        'normalized_name' => 'soultaker original soundtrack',
    ]);
    $file->albums()->sync([$album->id]);
    $file->metadata()->create([
        'payload' => [
            'title' => '11 -SoulTaker (TV Edit)',
            'duration' => 88.848,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('run.status', 'completed')
        ->assertJsonPath('run.proposal_count', 0)
        ->assertJsonPath('proposal', null);

    expect(AudioMetadataProposal::query()->where('file_id', $file->id)->count())->toBe(0);
});

test('local ai can accept a weak fingerprint candidate when source hints support it', function () {
    config([
        'services.audio_metadata.acoustid_client_key' => 'acoustid-client',
        'services.audio_metadata.acoustid_api_base_url' => 'https://acoustid.test/v2',
        'services.audio_metadata.ai_enabled' => true,
        'services.audio_metadata.ai_driver' => 'ollama',
        'services.audio_metadata.ai_base_url' => 'https://ollama.test',
        'services.audio_metadata.ai_model' => 'qwen-test',
    ]);

    $this->mock(AudioFingerprintService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forFile')
            ->once()
            ->andReturn(new AudioFingerprint('executioner-fingerprint', 447, '/tmp/audio.mp3'));
    });

    Http::fake([
        'https://acoustid.test/v2/lookup*' => Http::response([
            'status' => 'ok',
            'results' => [[
                'id' => 'acoustid-executioner',
                'score' => 0.955,
                'recordings' => [[
                    'id' => 'executioner-recording-mbid',
                    'title' => 'the executioner',
                    'duration' => 445000,
                    'artists' => [
                        ['name' => 'zts'],
                    ],
                ]],
            ]],
        ]),
        'https://ollama.test/api/chat' => Http::response([
            'message' => [
                'content' => json_encode([
                    'verdict' => 'accept',
                    'confidence' => 0.82,
                    'reason' => 'Filename and duration make the candidate plausible.',
                    'model' => 'qwen-test',
                ]),
            ],
        ]),
    ]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => '0h_executioner_m',
        'filename' => '0h_executioner_m.mp3',
    ]);
    $file->metadata()->create([
        'payload' => [
            'title' => '0h_executioner_m',
            'duration' => 446.90285714285716,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'acoustid_musicbrainz')
        ->assertJsonPath('proposal.confidence', 74)
        ->assertJsonPath('proposal.proposed_values', [])
        ->assertJsonPath('proposal.field_options.title.0.value', 'the executioner')
        ->assertJsonPath('proposal.field_options.artists.0.value', ['zts'])
        ->assertJsonPath('proposal.evidence.identity_support', 'weak')
        ->assertJsonPath('proposal.evidence.ai_review.verdict', 'accept')
        ->assertJsonPath('proposal.evidence.ai_review.model', 'qwen-test')
        ->assertJsonMissingPath('proposal.evidence.ai_review.confidence');
});

test('local metadata run can propose a better cover from musicbrainz release search', function () {
    config([
        'services.audio_metadata.musicbrainz_api_base_url' => 'https://musicbrainz.test',
        'services.audio_metadata.cover_art_archive_base_url' => 'https://cover.test',
    ]);

    $this->mock(AudioFingerprintService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forFile')
            ->once()
            ->andReturn(null);
    });

    Http::fake([
        'https://musicbrainz.test/ws/2/release/time-of-my-life-release*' => Http::response([
            'id' => 'time-of-my-life-release',
            'title' => 'Time of My Life',
            'date' => '2011-07-19',
            'country' => 'US',
            'label-info' => [[
                'catalog-number' => 'B0015663-02',
                'label' => ['name' => 'Universal Republic'],
            ]],
        ]),
        'https://musicbrainz.test/ws/2/release?*' => Http::response([
            'releases' => [[
                'id' => 'time-of-my-life-release',
                'score' => 100,
                'title' => 'Time of My Life',
                'artist-credit' => [[
                    'name' => '3 Doors Down',
                    'artist' => ['name' => '3 Doors Down'],
                ]],
            ]],
        ]),
        'https://cover.test/release/time-of-my-life-release' => Http::response([
            'images' => [[
                'front' => true,
                'image' => 'https://cover.test/release/time-of-my-life-release/front.jpg',
                'thumbnails' => [
                    'large' => 'https://cover.test/release/time-of-my-life-release/front-500.jpg',
                ],
            ]],
        ]),
    ]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'On The Run',
        'filename' => 'on-the-run.mp3',
    ]);
    $artist = Artist::factory()->create([
        'name' => '3 Doors Down',
        'normalized_name' => '3 doors down',
    ]);
    $album = Album::factory()->create([
        'name' => 'Time Of My Life',
        'normalized_name' => 'time of my life',
    ]);
    $file->artists()->sync([$artist->id]);
    $file->albums()->sync([$album->id]);
    AlbumCover::factory()->create([
        'album_id' => $album->id,
        'file_id' => $file->id,
        'path' => 'imports/old/cover.jpg',
        'path_hash' => hash('sha256', 'imports/old/cover.jpg'),
        'is_default' => true,
    ]);
    $file->metadata()->create([
        'payload' => [
            'title' => 'On The Run',
            'duration' => 187.742,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'musicbrainz_cover_art')
        ->assertJsonPath('proposal.proposed_values', [])
        ->assertJsonPath('proposal.field_options.cover_url.0.value', 'https://cover.test/release/time-of-my-life-release/front-500.jpg')
        ->assertJsonPath('proposal.field_options.release_label.0.value', 'Universal Republic')
        ->assertJsonPath('proposal.field_options.catalog_number.0.value', 'B0015663-02')
        ->assertJsonPath('proposal.field_options.release_date.0.value', '2011-07-19')
        ->assertJsonPath('proposal.field_options.release_country.0.value', 'US')
        ->assertJsonPath('proposal.evidence.source', 'musicbrainz_release_search')
        ->assertJsonPath('proposal.evidence.release_detail_source', 'musicbrainz_release_lookup')
        ->assertJsonPath('proposal.evidence.cover_source', 'cover_art_archive');
});

test('discogs details supplement musicbrainz cover proposals', function () {
    config([
        'services.audio_metadata.musicbrainz_api_base_url' => 'https://musicbrainz.test',
        'services.audio_metadata.cover_art_archive_base_url' => 'https://cover.test',
        'services.audio_metadata.discogs_user_token' => 'discogs-token',
        'services.audio_metadata.discogs_api_base_url' => 'https://discogs.test',
    ]);

    $this->mock(AudioFingerprintService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forFile')
            ->once()
            ->andReturn(null);
    });

    Http::fake([
        'https://musicbrainz.test/ws/2/release/time-of-my-life-release*' => Http::response([
            'id' => 'time-of-my-life-release',
            'title' => 'Time of My Life',
            'date' => '2011-07-19',
            'country' => 'US',
        ]),
        'https://musicbrainz.test/ws/2/release?*' => Http::response([
            'releases' => [[
                'id' => 'time-of-my-life-release',
                'score' => 100,
                'title' => 'Time of My Life',
                'artist-credit' => [[
                    'name' => '3 Doors Down',
                    'artist' => ['name' => '3 Doors Down'],
                ]],
            ]],
        ]),
        'https://cover.test/release/time-of-my-life-release' => Http::response([
            'images' => [[
                'front' => true,
                'image' => 'https://cover.test/release/time-of-my-life-release/front.jpg',
                'thumbnails' => [
                    'large' => 'https://cover.test/release/time-of-my-life-release/front-500.jpg',
                ],
            ]],
        ]),
        'https://discogs.test/database/search*' => Http::response([
            'results' => [
                ['id' => 4647572],
            ],
        ]),
        'https://discogs.test/releases/4647572' => Http::response([
            'id' => 4647572,
            'title' => 'Time Of My Life',
            'country' => 'US',
            'released' => '2011-07-19',
            'artists' => [
                ['name' => '3 Doors Down'],
            ],
            'labels' => [
                ['name' => 'Universal Republic', 'catno' => 'B0015663-02'],
            ],
            'identifiers' => [
                ['type' => 'Barcode', 'value' => '602527755550'],
            ],
            'tracklist' => [
                [
                    'position' => '2',
                    'title' => 'On The Run',
                    'duration' => '3:08',
                ],
            ],
        ]),
    ]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'On The Run',
        'filename' => 'on-the-run.mp3',
    ]);
    $artist = Artist::factory()->create([
        'name' => '3 Doors Down',
        'normalized_name' => '3 doors down',
    ]);
    $album = Album::factory()->create([
        'name' => 'Time Of My Life',
        'normalized_name' => 'time of my life',
    ]);
    $file->artists()->sync([$artist->id]);
    $file->albums()->sync([$album->id]);
    AlbumCover::factory()->create([
        'album_id' => $album->id,
        'file_id' => $file->id,
        'path' => 'imports/old/cover.jpg',
        'path_hash' => hash('sha256', 'imports/old/cover.jpg'),
        'is_default' => true,
    ]);
    $file->metadata()->create([
        'payload' => [
            'duration' => 187.742,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'multi_source_review')
        ->assertJsonPath('proposal.proposed_values', [])
        ->assertJsonPath('proposal.field_options.cover_url.0.value', 'https://cover.test/release/time-of-my-life-release/front-500.jpg')
        ->assertJsonPath('proposal.field_options.release_label.0.value', 'Universal Republic')
        ->assertJsonPath('proposal.field_options.catalog_number.0.value', 'B0015663-02')
        ->assertJsonPath('proposal.field_options.barcode.0.value', '602527755550')
        ->assertJsonPath('proposal.field_options.discogs_release_id.0.value', '4647572')
        ->assertJsonPath('proposal.evidence.source', 'musicbrainz_release_search')
        ->assertJsonPath('proposal.evidence.cover_source', 'cover_art_archive')
        ->assertJsonPath('proposal.evidence.discogs_release_id', '4647572')
        ->assertJsonPath('proposal.evidence.discogs_source', 'discogs_release_search');
});

test('metadata proposal applies external cover urls as album cover assets', function () {
    Storage::fake(AtlasStorage::DISK);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'On The Run',
    ]);
    $album = Album::factory()->create([
        'name' => 'Time Of My Life',
        'normalized_name' => 'time of my life',
    ]);
    $file->albums()->sync([$album->id]);
    $oldCover = AlbumCover::factory()->create([
        'album_id' => $album->id,
        'file_id' => $file->id,
        'path' => 'imports/old/cover.jpg',
        'path_hash' => hash('sha256', 'imports/old/cover.jpg'),
        'is_default' => true,
    ]);
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
        'provider' => 'musicbrainz_cover_art',
        'status' => 'pending',
        'confidence' => 82,
        'current_values' => ['cover_url' => '/api/audio/album-covers/'.$oldCover->id],
        'proposed_values' => ['cover_url' => 'https://cover.test/front.jpg'],
        'changes' => [
            'cover_url' => [
                'current' => '/api/audio/album-covers/'.$oldCover->id,
                'proposed' => 'https://cover.test/front.jpg',
            ],
        ],
        'evidence' => ['source' => 'musicbrainz_release_search'],
    ]);

    Http::fake([
        'https://cover.test/front.jpg' => Http::response('better-cover-bytes', 200, ['content-type' => 'image/jpeg']),
    ]);

    $response = $this->actingAs($user)->patchJson("/api/audio/metadata-proposals/{$proposal->id}", [
        'action' => 'apply',
        'fields' => ['cover_url'],
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('proposal.status', 'applied');

    $newCover = AlbumCover::query()
        ->where('album_id', $album->id)
        ->where('is_default', true)
        ->first();

    expect($newCover)->not->toBeNull()
        ->and($newCover?->id)->not->toBe($oldCover->id)
        ->and($oldCover->fresh()->is_default)->toBeFalse()
        ->and($newCover?->mime_type)->toBe('image/jpeg');

    Storage::disk(AtlasStorage::DISK)->assertExists((string) $newCover?->path);
});

test('metadata proposal applies cover urls to albums created by the same proposal', function () {
    Storage::fake(AtlasStorage::DISK);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Saboteur (Dub Mix)',
        'preview_url' => null,
    ]);
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
        'provider' => 'discogs_release',
        'status' => 'pending',
        'confidence' => 82,
        'current_values' => [
            'album' => null,
            'cover_url' => null,
        ],
        'proposed_values' => [
            'album' => 'All Or Nothing',
            'cover_url' => 'https://discogs.test/image/all-or-nothing.jpg',
        ],
        'changes' => [
            'album' => [
                'current' => null,
                'proposed' => 'All Or Nothing',
            ],
            'cover_url' => [
                'current' => null,
                'proposed' => 'https://discogs.test/image/all-or-nothing.jpg',
            ],
        ],
        'evidence' => ['source' => 'discogs_release_search'],
    ]);

    Http::fake([
        'https://discogs.test/image/all-or-nothing.jpg' => Http::response('cover-bytes', 200, ['content-type' => 'image/jpeg']),
    ]);

    $response = $this->actingAs($user)->patchJson("/api/audio/metadata-proposals/{$proposal->id}", [
        'action' => 'apply',
        'fields' => ['album', 'cover_url'],
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('proposal.status', 'applied');

    $album = $file->fresh()->albums()->first();
    $cover = $album?->covers()->where('is_default', true)->first();

    expect($album?->name)->toBe('All Or Nothing')
        ->and($file->fresh()?->preview_url)->toBeNull()
        ->and($cover)->not->toBeNull()
        ->and($cover?->mime_type)->toBe('image/jpeg');

    Storage::disk(AtlasStorage::DISK)->assertExists((string) $cover?->path);
});
