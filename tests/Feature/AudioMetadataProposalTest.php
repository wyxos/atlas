<?php

use App\Jobs\GenerateAudioMetadataRun;
use App\Models\AudioMetadataProposal;
use App\Models\File;
use App\Models\SpotifyToken;
use App\Models\User;
use App\Services\Audio\AudioFingerprint;
use App\Services\Audio\AudioFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

uses(RefreshDatabase::class);
test('single local metadata run stores a reviewable proposal without mutating the track', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'bandcamp',
        'mime_type' => 'audio/mpeg',
        'title' => 'raw filename',
        'filename' => 'raw-filename.mp3',
    ]);
    $file->metadata()->create([
        'payload' => [
            'probe' => [
                'format' => [
                    'duration' => '181.2',
                    'tags' => [
                        'title' => 'Tagged Title',
                        'artist' => 'Artist A; Artist B',
                        'album' => 'Tagged Album',
                    ],
                ],
            ],
        ],
    ]);
    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");
    $response->assertAccepted()
        ->assertJsonPath('run.scope', 'single')
        ->assertJsonPath('run.status', 'completed')
        ->assertJsonPath('run.proposal_count', 1)
        ->assertJsonPath('proposal.provider', 'local')
        ->assertJsonPath('proposal.status', 'pending')
        ->assertJsonPath('proposal.proposed_values.title', 'Tagged Title')
        ->assertJsonPath('proposal.proposed_values.artists', ['Artist A', 'Artist B'])
        ->assertJsonPath('proposal.proposed_values.album', 'Tagged Album')
        ->assertJsonPath('proposal.proposed_values.duration_seconds', 181);
    expect($file->fresh()->title)->toBe('raw filename')
        ->and($file->fresh()->artists()->count())->toBe(0)
        ->and($file->fresh()->albums()->count())->toBe(0);
});
test('single local metadata run prefers high confidence fingerprint metadata over noisy tags', function () {
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
            ->andReturn(new AudioFingerprint('fingerprint-body', 180, '/tmp/audio.mp3'));
    });
    Http::fake([
        'https://acoustid.test/v2/lookup*' => Http::response([
            'status' => 'ok',
            'results' => [[
                'id' => 'acoustid-track-id',
                'score' => 0.91,
                'recordings' => [[
                    'id' => 'recording-mbid',
                    'title' => 'Canonical Track',
                    'duration' => 180000,
                    'artists' => [
                        ['name' => 'Canonical Artist'],
                    ],
                    'releases' => [[
                        'id' => 'release-mbid',
                        'title' => 'Canonical Album',
                    ]],
                ]],
            ]],
        ]),
        'https://musicbrainz.test/ws/2/release/release-mbid*' => Http::response([
            'id' => 'release-mbid',
            'title' => 'Canonical Album',
            'date' => '2001-02-03',
            'country' => 'US',
            'barcode' => '012345678905',
            'label-info' => [[
                'catalog-number' => 'CAT-001',
                'label' => ['name' => 'Canonical Label'],
            ]],
            'media' => [[
                'position' => 1,
                'tracks' => [[
                    'number' => '8',
                    'position' => 8,
                    'recording' => ['id' => 'recording-mbid'],
                ]],
            ]],
        ]),
        'https://cover.test/release/release-mbid' => Http::response([
            'images' => [[
                'front' => true,
                'image' => 'https://cover.test/release/release-mbid/front.jpg',
                'thumbnails' => [
                    'large' => 'https://cover.test/release/release-mbid/front-500.jpg',
                ],
            ]],
        ]),
        'https://ollama.test/api/chat' => Http::response([
            'message' => [
                'content' => json_encode([
                    'verdict' => 'accept',
                    'confidence' => 0.92,
                    'reason' => 'The fingerprint candidate is coherent with the supplied source release metadata.',
                    'model' => 'qwen-test',
                ]),
            ],
        ]),
    ]);
    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Classmate Sauce Title',
        'filename' => 'mystery-track.mp3',
    ]);
    $file->metadata()->create([
        'payload' => [
            'probe' => [
                'format' => [
                    'tags' => [
                        'title' => 'Classmate Remix 2009',
                        'artist' => 'Wrong Friend',
                        'album' => 'USB Stick',
                    ],
                ],
            ],
        ],
    ]);
    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");
    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'acoustid_musicbrainz')
        ->assertJsonPath('proposal.confidence', 96)
        ->assertJsonPath('proposal.proposed_values.title', 'Canonical Track')
        ->assertJsonPath('proposal.proposed_values.artists', ['Canonical Artist'])
        ->assertJsonPath('proposal.proposed_values.album', 'Canonical Album')
        ->assertJsonPath('proposal.proposed_values.track_number', '8')
        ->assertJsonPath('proposal.proposed_values.disc_number', '1')
        ->assertJsonPath('proposal.proposed_values.release_label', 'Canonical Label')
        ->assertJsonPath('proposal.proposed_values.catalog_number', 'CAT-001')
        ->assertJsonPath('proposal.proposed_values.barcode', '012345678905')
        ->assertJsonPath('proposal.proposed_values.release_date', '2001-02-03')
        ->assertJsonPath('proposal.proposed_values.release_country', 'US')
        ->assertJsonPath('proposal.proposed_values.duration_seconds', 180)
        ->assertJsonPath('proposal.proposed_values.cover_url', 'https://cover.test/release/release-mbid/front-500.jpg')
        ->assertJsonPath('proposal.evidence.source', 'acoustid_fingerprint')
        ->assertJsonPath('proposal.evidence.acoustid_score', 91)
        ->assertJsonPath('proposal.evidence.musicbrainz_recording_id', 'recording-mbid')
        ->assertJsonPath('proposal.evidence.musicbrainz_release_id', 'release-mbid')
        ->assertJsonPath('proposal.evidence.duration_delta_seconds', 0)
        ->assertJsonPath('proposal.evidence.cover_source', 'cover_art_archive');
    expect($file->fresh()->title)->toBe('Classmate Sauce Title');
});

test('low confidence fingerprint metadata falls back to local tag proposal', function () {
    config([
        'services.audio_metadata.acoustid_client_key' => 'acoustid-client',
        'services.audio_metadata.acoustid_api_base_url' => 'https://acoustid.test/v2',
        'services.audio_metadata.cover_art_archive_base_url' => 'https://cover.test',
    ]);
    $this->mock(AudioFingerprintService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forFile')
            ->once()
            ->andReturn(new AudioFingerprint('weak-fingerprint', 180, '/tmp/audio.mp3'));
    });
    Http::fake([
        'https://acoustid.test/v2/lookup*' => Http::response([
            'status' => 'ok',
            'results' => [[
                'score' => 0.2,
                'recordings' => [[
                    'id' => 'weak-recording-mbid',
                    'title' => 'Weak Match',
                ]],
            ]],
        ]),
    ]);
    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Original Title',
    ]);
    $file->metadata()->create([
        'payload' => [
            'title' => 'Tagged Title',
            'artist' => 'Tagged Artist',
        ],
    ]);
    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");
    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'local')
        ->assertJsonPath('proposal.proposed_values.title', 'Tagged Title')
        ->assertJsonPath('proposal.proposed_values.artists', ['Tagged Artist'])
        ->assertJsonPath('proposal.evidence.source', 'embedded_tags');
});

test('metadata proposal can apply selected fields to canonical audio metadata', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Original Title',
    ]);
    $file->metadata()->create([
        'payload' => [
            'title' => 'Proposed Title',
            'artist' => 'Artist A',
            'album' => 'Album A',
            'duration_seconds' => 212,
        ],
    ]);
    $proposalId = $this->actingAs($user)
        ->postJson("/api/audio/{$file->id}/metadata-runs")
        ->json('proposal.id');
    $response = $this->actingAs($user)->patchJson("/api/audio/metadata-proposals/{$proposalId}", [
        'action' => 'apply',
        'fields' => ['title', 'artists', 'album'],
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('proposal.status', 'applied');

    $file = $file->fresh();
    $payload = $file->metadata()->first()?->payload ?? [];

    expect($file->title)->toBe('Proposed Title')
        ->and($file->artists()->pluck('name')->all())->toBe(['Artist A'])
        ->and($file->albums()->pluck('name')->all())->toBe(['Album A'])
        ->and($payload['audio']['aliases'] ?? null)->toBeNull()
        ->and($payload['duration_seconds'] ?? null)->toBe(212);
});

test('metadata proposal can apply release details and track numbers from embedded tags', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => '08-sash_feat_tina_cousins-mysterious_times_remix',
    ]);
    $file->metadata()->create([
        'payload' => [
            'probe' => [
                'format' => [
                    'duration' => '383.2',
                    'tags' => [
                        'title' => 'Mysterious Times (Marc Lime & K Bastian Remix)',
                        'artist' => 'Sash! Feat Tina Cousins',
                        'album' => 'Mysterious Times (UK-Remixes EP)',
                        'track' => '08/12',
                        'disc' => '1/2',
                        'label' => 'Tokapi Recordings',
                        'catalog_number' => 'TR011',
                        'barcode' => '8715576130112',
                        'date' => '2011-06-23',
                        'country' => 'GB',
                        'isrc' => 'GBBKS1100011',
                        'musicbrainz_recording_id' => 'recording-mbid',
                        'musicbrainz_release_id' => 'release-mbid',
                        'discogs_release_id' => '2969820',
                    ],
                ],
            ],
        ],
    ]);

    $proposalId = $this->actingAs($user)
        ->postJson("/api/audio/{$file->id}/metadata-runs")
        ->assertAccepted()
        ->assertJsonPath('proposal.proposed_values.album', 'Mysterious Times (UK-Remixes EP)')
        ->assertJsonPath('proposal.proposed_values.track_number', '08')
        ->assertJsonPath('proposal.proposed_values.disc_number', '1')
        ->assertJsonPath('proposal.proposed_values.release_label', 'Tokapi Recordings')
        ->json('proposal.id');

    $this->actingAs($user)->patchJson("/api/audio/metadata-proposals/{$proposalId}", [
        'action' => 'apply',
        'fields' => [
            'title',
            'artists',
            'album',
            'track_number',
            'disc_number',
            'release_label',
            'catalog_number',
            'barcode',
            'release_date',
            'release_country',
            'isrc',
            'musicbrainz_recording_id',
            'musicbrainz_release_id',
            'discogs_release_id',
        ],
    ])->assertSuccessful()
        ->assertJsonPath('proposal.status', 'applied');

    $file = $file->fresh(['artists', 'albums']);
    $album = $file->albums->first();

    expect($file->title)->toBe('Mysterious Times (Marc Lime & K Bastian Remix)')
        ->and($file->artists->pluck('name')->all())->toBe(['Sash! Feat Tina Cousins'])
        ->and($album?->name)->toBe('Mysterious Times (UK-Remixes EP)')
        ->and($album?->pivot?->track_number)->toBe('08')
        ->and($album?->pivot?->disc_number)->toBe('1')
        ->and($album?->release_label)->toBe('Tokapi Recordings')
        ->and($album?->catalog_number)->toBe('TR011')
        ->and($album?->barcode)->toBe('8715576130112')
        ->and($album?->release_date)->toBe('2011-06-23')
        ->and($album?->release_country)->toBe('GB')
        ->and($album?->musicbrainz_release_id)->toBe('release-mbid')
        ->and($album?->discogs_release_id)->toBe('2969820')
        ->and($file->metadata()->first()?->payload['isrc'] ?? null)->toBe('GBBKS1100011')
        ->and($file->metadata()->first()?->payload['musicbrainz_recording_id'] ?? null)->toBe('recording-mbid');
});

test('metadata run returns no proposal after matching metadata has been applied', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Original Title',
    ]);
    $file->metadata()->create([
        'payload' => [
            'title' => 'Proposed Title',
            'artist' => 'Artist A',
            'album' => 'Album A',
            'duration_seconds' => 212,
        ],
    ]);

    $proposalId = $this->actingAs($user)
        ->postJson("/api/audio/{$file->id}/metadata-runs")
        ->json('proposal.id');

    $this->actingAs($user)->patchJson("/api/audio/metadata-proposals/{$proposalId}", [
        'action' => 'apply',
        'fields' => ['title', 'artists', 'album'],
    ])->assertSuccessful();

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('run.status', 'completed')
        ->assertJsonPath('run.proposal_count', 0)
        ->assertJsonPath('proposal', null);
});

test('metadata proposal can ignore pending changes', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Original Title',
    ]);
    $file->metadata()->create(['payload' => ['title' => 'Proposed Title']]);

    $proposalId = $this->actingAs($user)
        ->postJson("/api/audio/{$file->id}/metadata-runs")
        ->json('proposal.id');

    $response = $this->actingAs($user)->patchJson("/api/audio/metadata-proposals/{$proposalId}", [
        'action' => 'ignore',
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('proposal.status', 'ignored');

    expect($file->fresh()->title)->toBe('Original Title');
});

test('spotify metadata run refetches spotify and proposes drift plus better cover', function () {
    config([
        'services.spotify.api_base_url' => 'https://spotify.test/v1',
    ]);

    $user = User::factory()->create();
    SpotifyToken::query()->create([
        'user_id' => $user->id,
        'access_token' => 'spotify-access-token',
        'refresh_token' => 'spotify-refresh-token',
        'expires_at' => now()->addHour(),
        'scope' => 'user-read-private',
    ]);

    Http::fake([
        'https://spotify.test/v1/tracks/1A2B3C4D5E6F7G8H9I0J1K' => Http::response([
            'name' => 'Spotify Title',
            'uri' => 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K',
            'duration_ms' => 194000,
            'external_ids' => ['isrc' => 'ISRC123'],
            'artists' => [
                ['name' => 'Spotify Artist'],
            ],
            'album' => [
                'name' => 'Spotify Album',
                'images' => [
                    ['url' => 'https://img.test/small.jpg', 'width' => 64],
                    ['url' => 'https://img.test/large.jpg', 'width' => 640],
                ],
            ],
        ]),
    ]);

    $file = File::factory()->create([
        'source' => 'spotify',
        'source_id' => '1A2B3C4D5E6F7G8H9I0J1K',
        'mime_type' => 'audio/mpeg',
        'title' => 'Old Spotify Title',
        'preview_url' => null,
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'spotify')
        ->assertJsonPath('proposal.confidence', 98)
        ->assertJsonPath('proposal.proposed_values.title', 'Spotify Title')
        ->assertJsonPath('proposal.proposed_values.artists', ['Spotify Artist'])
        ->assertJsonPath('proposal.proposed_values.album', 'Spotify Album')
        ->assertJsonPath('proposal.proposed_values.duration_seconds', 194)
        ->assertJsonPath('proposal.proposed_values.cover_url', 'https://img.test/large.jpg');
});

test('batch metadata run is queued and scoped to audio', function () {
    Queue::fake([GenerateAudioMetadataRun::class]);

    $user = User::factory()->create();
    File::factory()->create(['mime_type' => 'audio/mpeg', 'source' => 'local']);
    File::factory()->create(['mime_type' => 'audio/ogg', 'source' => 'spotify']);
    File::factory()->create(['mime_type' => 'image/jpeg', 'source' => 'local']);

    $response = $this->actingAs($user)->postJson('/api/audio/metadata-runs', [
        'scope' => 'all',
        'source_filter' => 'local',
    ]);

    $response->assertAccepted()
        ->assertJsonPath('run.scope', 'all')
        ->assertJsonPath('run.source_filter', 'local')
        ->assertJsonPath('run.total_files', 1)
        ->assertJsonPath('run.status', 'pending');

    Queue::assertPushed(
        GenerateAudioMetadataRun::class,
        fn (GenerateAudioMetadataRun $job): bool => $job->queue === 'library-scans'
    );
});

test('latest proposal endpoint returns the current user pending proposal only', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $file = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'title' => 'Original Title',
    ]);
    $file->metadata()->create(['payload' => ['title' => 'Proposed Title']]);
    $this->actingAs($otherUser)->postJson("/api/audio/{$file->id}/metadata-runs");
    $proposalId = $this->actingAs($user)
        ->postJson("/api/audio/{$file->id}/metadata-runs")
        ->json('proposal.id');
    $response = $this->actingAs($user)->getJson("/api/audio/{$file->id}/metadata-proposals/latest");

    $response->assertSuccessful()
        ->assertJsonPath('proposal.id', $proposalId);

    $this->actingAs($user)->patchJson("/api/audio/metadata-proposals/{$proposalId}", [
        'action' => 'apply',
        'fields' => ['title'],
    ])->assertSuccessful();

    $this->actingAs($user)
        ->getJson("/api/audio/{$file->id}/metadata-proposals/latest")
        ->assertSuccessful()
        ->assertJsonPath('proposal', null);

    expect(AudioMetadataProposal::query()->where('file_id', $file->id)->count())->toBe(2);
});
