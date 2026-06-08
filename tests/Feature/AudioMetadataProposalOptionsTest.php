<?php

use App\Models\AudioMetadataProposal;
use App\Models\AudioMetadataRun;
use App\Models\File;
use App\Models\User;
use App\Services\Audio\AudioFingerprint;
use App\Services\Audio\AudioFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('partial fingerprint review does not hide later album cover candidates', function () {
    config([
        'services.audio_metadata.acoustid_client_key' => 'acoustid-client',
        'services.audio_metadata.acoustid_api_base_url' => 'https://acoustid.test/v2',
        'services.audio_metadata.musicbrainz_api_base_url' => 'https://musicbrainz.test',
        'services.audio_metadata.cover_art_archive_base_url' => 'https://cover.test',
        'services.audio_metadata.ai_enabled' => true,
        'services.audio_metadata.ai_driver' => 'ollama',
        'services.audio_metadata.ai_base_url' => 'https://ollama.test',
        'services.audio_metadata.ai_model' => 'qwen-test',
    ]);

    $this->mock(AudioFingerprintService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forFile')
            ->once()
            ->andReturn(new AudioFingerprint('one-more-time-fingerprint', 322, '/tmp/one-more-time.mp3'));
    });

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_starts_with($url, 'https://acoustid.test/v2/lookup')) {
            return Http::response([
                'status' => 'ok',
                'results' => [[
                    'id' => 'acoustid-one-more-time',
                    'score' => 1.0,
                    'recordings' => [[
                        'id' => 'one-more-time-recording-mbid',
                        'title' => 'One More Time',
                        'duration' => 319000,
                        'artists' => [
                            ['name' => 'Daft Punk'],
                        ],
                        'releases' => [[
                            'id' => 'nrj-story-release-mbid',
                            'title' => 'NRJ Story',
                            'date' => '2002',
                            'country' => 'FR',
                        ]],
                    ]],
                ]],
            ]);
        }

        if ($url === 'https://musicbrainz.test/ws/2/release/nrj-story-release-mbid') {
            return Http::response([
                'id' => 'nrj-story-release-mbid',
                'title' => 'NRJ Story',
                'date' => '2002',
                'country' => 'FR',
                'media' => [[
                    'position' => 1,
                    'tracks' => [[
                        'number' => '4',
                        'position' => 4,
                        'recording' => ['id' => 'one-more-time-recording-mbid'],
                    ]],
                ]],
            ]);
        }

        if ($url === 'https://cover.test/release/nrj-story-release-mbid') {
            return Http::response([
                'images' => [[
                    'front' => true,
                    'image' => 'https://cover.test/release/nrj-story/front.jpg',
                ]],
            ]);
        }

        if (str_starts_with($url, 'https://musicbrainz.test/ws/2/release?')) {
            return Http::response([
                'releases' => [[
                    'id' => 'discovery-release-mbid',
                    'title' => 'Discovery',
                    'date' => '2001-03-12',
                    'country' => 'XE',
                    'score' => 100,
                    'artist-credit' => [[
                        'name' => 'Daft Punk',
                        'artist' => ['name' => 'Daft Punk'],
                    ]],
                ]],
            ]);
        }

        if ($url === 'https://musicbrainz.test/ws/2/release/discovery-release-mbid') {
            return Http::response([
                'id' => 'discovery-release-mbid',
                'title' => 'Discovery',
                'date' => '2001-03-12',
                'country' => 'XE',
                'barcode' => '724354278228',
                'label-info' => [[
                    'catalog-number' => '724354278228',
                    'label' => ['name' => 'Virgin'],
                ]],
            ]);
        }

        if ($url === 'https://cover.test/release/discovery-release-mbid') {
            return Http::response([
                'images' => [[
                    'front' => true,
                    'image' => 'https://cover.test/release/discovery/front.jpg',
                    'thumbnails' => [
                        '500' => 'https://cover.test/release/discovery/front-500.jpg',
                    ],
                ]],
            ]);
        }

        if ($url === 'https://ollama.test/api/chat') {
            return Http::response([
                'message' => [
                    'content' => json_encode([
                        'verdict' => 'ambiguous',
                        'confidence' => 0.82,
                        'reason' => 'The recording is right, but the attached MusicBrainz release is a compilation.',
                        'model' => 'qwen-test',
                        'safe_fields' => ['artists', 'duration_seconds'],
                    ]),
                ],
            ]);
        }

        return Http::response([], 404);
    });

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'One More Time',
    ]);
    $file->artists()->create([
        'name' => 'Daft Punk',
        'normalized_name' => 'daft punk',
    ]);
    $file->albums()->create([
        'name' => 'Discovery',
        'normalized_name' => 'discovery',
    ]);
    $file->metadata()->create([
        'payload' => [
            'title' => 'One More Time',
            'artist' => 'Daft Punk',
            'album' => 'Discovery',
            'duration' => 322,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'multi_source_review')
        ->assertJsonPath('proposal.proposed_values.cover_url', 'https://cover.test/release/discovery/front-500.jpg')
        ->assertJsonPath('proposal.proposed_values.musicbrainz_release_id', 'discovery-release-mbid')
        ->assertJsonPath('proposal.field_options.album.0.value', 'NRJ Story')
        ->assertJsonPath('proposal.field_options.album.0.recommended', false)
        ->assertJsonPath('proposal.field_options.album.0.source_label', 'MusicBrainz release')
        ->assertJsonPath('proposal.field_options.album.0.source_url', 'https://musicbrainz.org/release/nrj-story-release-mbid')
        ->assertJsonPath('proposal.field_options.album.1.value', 'Discovery')
        ->assertJsonPath('proposal.field_options.album.1.recommended', true)
        ->assertJsonPath('proposal.field_options.album.1.source_label', 'MusicBrainz release')
        ->assertJsonPath('proposal.field_options.album.1.source_url', 'https://musicbrainz.org/release/discovery-release-mbid')
        ->assertJsonPath('proposal.field_options.cover_url.0.value', 'https://cover.test/release/nrj-story/front.jpg')
        ->assertJsonPath('proposal.field_options.cover_url.0.recommended', false)
        ->assertJsonPath('proposal.field_options.cover_url.0.source_url', 'https://musicbrainz.org/release/nrj-story-release-mbid')
        ->assertJsonPath('proposal.field_options.cover_url.1.value', 'https://cover.test/release/discovery/front-500.jpg')
        ->assertJsonPath('proposal.field_options.cover_url.1.recommended', true)
        ->assertJsonPath('proposal.field_options.cover_url.1.source_url', 'https://musicbrainz.org/release/discovery-release-mbid')
        ->assertJsonPath('run.proposal_count', 1)
        ->assertJsonPath('run.failed_files', 0);
});

test('metadata proposal can apply manually selected provider option values', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Original Title',
    ]);
    $file->metadata()->create(['payload' => []]);
    $run = AudioMetadataRun::query()->create([
        'user_id' => $user->id,
        'scope' => 'single',
        'source_filter' => 'all',
        'status' => 'completed',
        'total_files' => 1,
        'processed_files' => 1,
        'proposal_count' => 1,
        'failed_files' => 0,
    ]);
    $proposal = AudioMetadataProposal::query()->create([
        'audio_metadata_run_id' => $run->id,
        'file_id' => $file->id,
        'provider' => 'multi_source_review',
        'status' => 'pending',
        'current_values' => [
            'album' => 'Current Album',
            'cover_url' => null,
        ],
        'proposed_values' => [],
        'changes' => [],
        'evidence' => [
            'field_options' => [
                'album' => [[
                    'id' => 'album-discogs-option',
                    'provider' => 'discogs_release',
                    'confidence' => 90,
                    'value' => 'Selected Album',
                    'recommended' => false,
                ]],
                'cover_url' => [[
                    'id' => 'cover-discogs-option',
                    'provider' => 'discogs_release',
                    'confidence' => 90,
                    'value' => 'https://cover.test/selected.jpg',
                    'recommended' => false,
                ]],
            ],
        ],
    ]);

    Http::fake([
        'https://cover.test/selected.jpg' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $this->actingAs($user)->patchJson("/api/audio/metadata-proposals/{$proposal->id}", [
        'action' => 'apply',
        'fields' => ['album', 'cover_url'],
        'field_options' => [
            'album' => 'album-discogs-option',
            'cover_url' => 'cover-discogs-option',
        ],
    ])->assertSuccessful()
        ->assertJsonPath('proposal.status', 'applied')
        ->assertJsonPath('proposal.proposed_values.album', 'Selected Album')
        ->assertJsonPath('proposal.proposed_values.cover_url', 'https://cover.test/selected.jpg');

    $file = $file->fresh(['albums.defaultCover']);
    $album = $file->albums->first();

    expect($album?->name)->toBe('Selected Album')
        ->and($album?->defaultCover?->path)->not->toBeNull();
});
