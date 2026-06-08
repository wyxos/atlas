<?php

use App\Models\Album;
use App\Models\Artist;
use App\Models\AudioMetadataProposal;
use App\Models\AudioMetadataRun;
use App\Models\File;
use App\Models\User;
use App\Services\Audio\AudioFingerprint;
use App\Services\Audio\AudioFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('fingerprint metadata surfaces manual options when required ai field review is unusable', function () {
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
            ->andReturn(new AudioFingerprint('air-for-life-fingerprint', 530, '/tmp/air-for-life.mp3'));
    });

    $aiCalls = 0;

    Http::fake(function (Request $request) use (&$aiCalls) {
        $url = $request->url();

        if (str_starts_with($url, 'https://acoustid.test/v2/lookup')) {
            return Http::response([
                'status' => 'ok',
                'results' => [[
                    'id' => 'acoustid-air-for-life',
                    'score' => 1.0,
                    'recordings' => [[
                        'id' => 'air-for-life-recording-mbid',
                        'title' => 'Air for Life (Above & Beyond 2012 Update)',
                        'duration' => 531000,
                        'artists' => [
                            ['name' => 'Above & Beyond'],
                            ['name' => 'Andy Moor'],
                        ],
                        'releases' => [[
                            'id' => 'air-for-life-remixes-release',
                            'title' => 'Air for Life (The Remixes)',
                            'date' => '2012-07-24',
                            'country' => 'GB',
                        ]],
                    ]],
                ]],
            ]);
        }

        if ($url === 'https://musicbrainz.test/ws/2/release/air-for-life-remixes-release') {
            return Http::response([
                'id' => 'air-for-life-remixes-release',
                'title' => 'Air for Life (The Remixes)',
                'date' => '2012-07-24',
                'country' => 'GB',
                'label-info' => [[
                    'catalog-number' => 'ANJ049RD',
                    'label' => ['name' => 'Anjunabeats'],
                ]],
                'media' => [[
                    'position' => 1,
                    'tracks' => [[
                        'number' => '2',
                        'position' => 2,
                        'recording' => ['id' => 'air-for-life-recording-mbid'],
                    ]],
                ]],
            ]);
        }

        if ($url === 'https://cover.test/release/air-for-life-remixes-release') {
            return Http::response([
                'images' => [[
                    'front' => true,
                    'image' => 'https://cover.test/release/air-for-life-remixes-release/front.jpg',
                ]],
            ]);
        }

        if ($url === 'https://ollama.test/api/chat') {
            $aiCalls++;

            return Http::response([
                'message' => [
                    'content' => 'not-json',
                ],
            ]);
        }

        return Http::response([], 404);
    });

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Air For Life (Mirco De Govia Remix)',
        'filename' => 'air-for-life-mirco-de-govia-remix.mp3',
    ]);
    $artist = Artist::factory()->create([
        'name' => 'Above & Beyond',
        'normalized_name' => 'above beyond',
    ]);
    $album = Album::factory()->create([
        'name' => 'Air For Life__Incl Mirco De Govia Remix Vinyl',
        'normalized_name' => 'air for life incl mirco de govia remix vinyl',
    ]);
    $file->artists()->sync([$artist->id]);
    $file->albums()->sync([$album->id]);
    $file->metadata()->create([
        'payload' => [
            'title' => 'Air For Life (Mirco De Govia Remix)',
            'artist' => 'Above & Beyond',
            'album' => 'Air For Life__Incl Mirco De Govia Remix Vinyl',
            'duration' => 530,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'acoustid_musicbrainz')
        ->assertJsonPath('proposal.proposed_values', [])
        ->assertJsonPath('proposal.field_options.album.0.value', 'Air for Life (The Remixes)')
        ->assertJsonPath('proposal.field_options.album.0.recommended', false)
        ->assertJsonPath('proposal.field_options.album.0.reason', 'AI response JSON could not be decoded.')
        ->assertJsonPath('proposal.field_options.cover_url.0.value', 'https://cover.test/release/air-for-life-remixes-release/front.jpg')
        ->assertJsonPath('proposal.field_options.cover_url.0.recommended', false)
        ->assertJsonPath('run.status', 'completed')
        ->assertJsonPath('run.processed_files', 1)
        ->assertJsonPath('run.failed_files', 0)
        ->assertJsonPath('run.proposal_count', 1)
        ->assertJsonPath('run.error', null);

    $run = AudioMetadataRun::query()->findOrFail($response->json('run.id'));

    expect($run->failed_files)->toBe(0)
        ->and(AudioMetadataProposal::query()->where('file_id', $file->id)->exists())->toBeTrue()
        ->and($aiCalls)->toBe(1);
});

test('metadata lookup falls back when top fingerprint candidate is rejected by field review', function () {
    config([
        'services.audio_metadata.acoustid_client_key' => 'acoustid-client',
        'services.audio_metadata.acoustid_api_base_url' => 'https://acoustid.test/v2',
        'services.audio_metadata.musicbrainz_api_base_url' => 'https://musicbrainz.test',
        'services.audio_metadata.cover_art_archive_base_url' => 'https://cover.test',
        'services.audio_metadata.ai_enabled' => true,
        'services.audio_metadata.ai_driver' => 'gateway',
        'services.audio_metadata.ai_base_url' => 'https://ollama.test',
        'services.audio_metadata.ai_token' => 'ai-token',
        'services.audio_metadata.ai_model' => 'qwen-test',
    ]);

    $this->mock(AudioFingerprintService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forFile')
            ->once()
            ->andReturn(new AudioFingerprint('untouchable-fingerprint', 190, '/tmp/hey-dude.mp3'));
    });

    $aiFieldReviewCalls = 0;

    Http::fake(function (Request $request) use (&$aiFieldReviewCalls) {
        $url = $request->url();

        if (str_starts_with($url, 'https://acoustid.test/v2/lookup')) {
            return Http::response([
                'status' => 'ok',
                'results' => [[
                    'id' => 'acoustid-hey-dude',
                    'score' => 1.0,
                    'recordings' => [[
                        'id' => 'hey-dude-recording-mbid',
                        'title' => 'Hey, Dude',
                        'duration' => 190000,
                        'artists' => [
                            ['name' => 'Before Their Eyes'],
                        ],
                        'releases' => [[
                            'id' => 'untouchable-release-mbid',
                            'title' => 'Untouchable',
                            'date' => '2010-03-09',
                            'country' => 'US',
                            'artist-credit' => [[
                                'name' => 'Before Their Eyes',
                                'artist' => ['name' => 'Before Their Eyes'],
                            ]],
                        ]],
                    ]],
                ]],
            ]);
        }

        if ($url === 'https://musicbrainz.test/ws/2/release/untouchable-release-mbid') {
            return Http::response([
                'id' => 'untouchable-release-mbid',
                'title' => 'Untouchable',
                'date' => '2010-03-09',
                'country' => 'US',
                'label-info' => [[
                    'catalog-number' => 'RR-123',
                    'label' => ['name' => 'Rise Records'],
                ]],
                'media' => [[
                    'position' => 1,
                    'tracks' => [[
                        'number' => '1',
                        'position' => 1,
                        'recording' => ['id' => 'hey-dude-recording-mbid'],
                    ]],
                ]],
            ]);
        }

        if (str_starts_with($url, 'https://musicbrainz.test/ws/2/release?')) {
            return Http::response([
                'releases' => [[
                    'id' => 'untouchable-release-mbid',
                    'title' => 'Untouchable',
                    'date' => '2010-03-09',
                    'country' => 'US',
                    'score' => 100,
                    'artist-credit' => [[
                        'name' => 'Before Their Eyes',
                        'artist' => ['name' => 'Before Their Eyes'],
                    ]],
                ]],
            ]);
        }

        if ($url === 'https://cover.test/release/untouchable-release-mbid') {
            return Http::response([
                'images' => [[
                    'front' => true,
                    'thumbnails' => [
                        '500' => 'https://cover.test/release/untouchable-release-mbid/front-500.jpg',
                    ],
                    'image' => 'https://cover.test/release/untouchable-release-mbid/front.jpg',
                ]],
            ]);
        }

        if ($url === 'https://ollama.test/v1/audio/metadata-review') {
            $aiFieldReviewCalls++;

            return Http::response([
                'verdict' => 'reject',
                'confidence' => 0.91,
                'reason' => 'Reject the fingerprint package for this test so the next source candidate must be tried.',
                'model' => 'qwen-test',
                'safe_fields' => [],
            ]);
        }

        return Http::response([], 404);
    });

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Hey Dude!',
        'filename' => 'hey-dude.mp3',
    ]);
    $artist = Artist::factory()->create([
        'name' => 'Before Their Eyes',
        'normalized_name' => 'before their eyes',
    ]);
    $album = Album::factory()->create([
        'name' => 'Untouchable',
        'normalized_name' => 'untouchable',
    ]);
    $file->artists()->sync([$artist->id]);
    $file->albums()->sync([$album->id]);
    $file->metadata()->create([
        'payload' => [
            'title' => 'Hey Dude!',
            'artist' => 'Before Their Eyes',
            'album' => 'Untouchable',
            'duration' => 190,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'musicbrainz_cover_art')
        ->assertJsonPath('proposal.proposed_values.album', 'Untouchable')
        ->assertJsonPath('proposal.proposed_values.release_date', '2010-03-09')
        ->assertJsonPath('proposal.proposed_values.release_country', 'US')
        ->assertJsonPath('proposal.proposed_values.musicbrainz_release_id', 'untouchable-release-mbid')
        ->assertJsonPath('proposal.proposed_values.cover_url', 'https://cover.test/release/untouchable-release-mbid/front-500.jpg')
        ->assertJsonPath('run.proposal_count', 1)
        ->assertJsonPath('run.failed_files', 0);

    expect($aiFieldReviewCalls)->toBe(1);
});
