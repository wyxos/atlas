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
        'services.audio_metadata.cover_art_archive_base_url' => 'https://cover.test',
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
        'https://cover.test/release/release-mbid' => Http::response([
            'images' => [[
                'front' => true,
                'image' => 'https://cover.test/release/release-mbid/front.jpg',
                'thumbnails' => [
                    'large' => 'https://cover.test/release/release-mbid/front-500.jpg',
                ],
            ]],
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
    expect($file->title)->toBe('Proposed Title')
        ->and($file->artists()->pluck('name')->all())->toBe(['Artist A'])
        ->and($file->albums()->pluck('name')->all())->toBe(['Album A'])
        ->and($file->metadata()->first()?->payload['duration_seconds'] ?? null)->toBe(212);
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
