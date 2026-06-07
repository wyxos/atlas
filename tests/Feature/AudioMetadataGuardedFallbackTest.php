<?php

use App\Models\Album;
use App\Models\Artist;
use App\Models\File;
use App\Models\User;
use App\Services\Audio\AudioFingerprint;
use App\Services\Audio\AudioFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('local ai discogs search runs when fingerprint candidates are guarded out', function () {
    config([
        'services.audio_metadata.acoustid_client_key' => 'acoustid-client',
        'services.audio_metadata.acoustid_api_base_url' => 'https://acoustid.test/v2',
        'services.audio_metadata.musicbrainz_api_base_url' => 'https://musicbrainz.test',
        'services.audio_metadata.discogs_user_token' => 'discogs-token',
        'services.audio_metadata.discogs_api_base_url' => 'https://discogs.test',
        'services.audio_metadata.ai_enabled' => true,
        'services.audio_metadata.ai_driver' => 'gateway',
        'services.audio_metadata.ai_base_url' => 'https://ollama.test',
        'services.audio_metadata.ai_token' => 'ai-token',
        'services.audio_metadata.ai_model' => 'qwen-test',
    ]);

    $this->mock(AudioFingerprintService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forFile')
            ->once()
            ->andReturn(new AudioFingerprint('bring-the-noise-fingerprint', 398, '/tmp/bring-the-noise.mp3'));
    });

    $aiSchemas = [];
    $discogsSearches = [];

    Http::fake(function (Request $request) use (&$aiSchemas, &$discogsSearches) {
        $url = $request->url();

        if (str_starts_with($url, 'https://acoustid.test/v2/lookup')) {
            return Http::response([
                'status' => 'ok',
                'results' => [[
                    'id' => 'acoustid-bring-the-noise',
                    'score' => 0.997,
                    'recordings' => [[
                        'id' => 'bring-noise-recording-mbid',
                        'title' => 'Bring the Noise (Benny Benassi Pump-Kin instrumental)',
                        'duration' => 398000,
                        'artists' => [
                            ['name' => 'Public Enemy'],
                        ],
                        'releases' => [[
                            'id' => 'bring-noise-single-mbid',
                            'title' => 'Bring the Noise (remix) / Give It Up',
                            'date' => '2007-09-11',
                            'country' => 'US',
                        ]],
                    ]],
                ]],
            ]);
        }

        if ($url === 'https://musicbrainz.test/ws/2/release/bring-noise-single-mbid') {
            return Http::response([
                'id' => 'bring-noise-single-mbid',
                'title' => 'Bring the Noise (remix) / Give It Up',
                'date' => '2007-09-11',
                'country' => 'US',
                'label-info' => [[
                    'catalog-number' => 'UL 1584-2',
                    'label' => ['name' => 'Ultra Records'],
                ]],
                'media' => [[
                    'position' => 1,
                    'tracks' => [[
                        'number' => '7',
                        'position' => 7,
                        'recording' => ['id' => 'bring-noise-recording-mbid'],
                    ]],
                ]],
            ]);
        }

        if (str_starts_with($url, 'https://musicbrainz.test/ws/2/release?')) {
            return Http::response(['releases' => []]);
        }

        if (str_starts_with($url, 'https://coverartarchive.org/release/')) {
            return Http::response([], 404);
        }

        if (str_starts_with($url, 'https://discogs.test/database/search')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $discogsSearches[] = [
                'release_title' => (string) ($query['release_title'] ?? ''),
                'artist' => (string) ($query['artist'] ?? ''),
                'q' => (string) ($query['q'] ?? ''),
            ];

            return Http::response([
                'results' => ($query['release_title'] ?? null) === "Rock N' Rave"
                    && ($query['artist'] ?? null) === 'Benny Benassi'
                        ? [['id' => 14839269]]
                        : [],
            ]);
        }

        if ($url === 'https://discogs.test/releases/14839269') {
            return Http::response([
                'id' => 14839269,
                'title' => "Rock'N'Rave",
                'country' => 'Russia',
                'released' => '2008',
                'artists' => [
                    ['name' => 'Benny Benassi'],
                ],
                'labels' => [
                    ['name' => 'Ultra Records (16)', 'catno' => 'UL 1695-2'],
                ],
                'images' => [[
                    'type' => 'primary',
                    'uri' => 'https://discogs.test/image/rock-n-rave-primary.jpg',
                ]],
                'tracklist' => [[
                    'position' => '2-1',
                    'title' => 'Bring The Noise Remix (Pump-kin Remix)',
                    'duration' => '6:38',
                ]],
            ]);
        }

        if ($url === 'https://ollama.test/v1/audio/metadata-review') {
            $schema = (string) ($request->data()['schemaVersion'] ?? '');
            $aiSchemas[] = $schema;

            return Http::response(match ($schema) {
                'atlas-audio-metadata-discogs-search-v1' => [
                    'queries' => [[
                        'release_title' => "Rock N' Rave",
                        'artist' => 'Benny Benassi',
                        'reason' => 'Use the source release title without the local disc suffix.',
                    ]],
                    'model' => 'qwen-test',
                ],
                'atlas-audio-metadata-anomaly-v1' => [
                    'verdict' => 'accept',
                    'confidence' => 0.88,
                    'reason' => 'Same album family, matching remix title, and matching duration.',
                    'model' => 'qwen-test',
                    'selected_track_position' => '2-1',
                    'selected_track_title' => 'Bring The Noise Remix (Pump-kin Remix)',
                ],
                default => [
                    'verdict' => 'accept',
                    'confidence' => 0.84,
                    'reason' => 'Fingerprint proves recording only; source-album validation is still required.',
                    'model' => 'qwen-test',
                ],
            });
        }

        return Http::response([], 404);
    });

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Bring the Noise Remix [Pump-Kin Remix]',
        'filename' => 'bring-the-noise-pump-kin-remix.mp3',
    ]);
    $artist = Artist::factory()->create([
        'name' => 'Benny Benassi',
        'normalized_name' => 'benny benassi',
    ]);
    $album = Album::factory()->create([
        'name' => "Rock N' Rave Disc 2",
        'normalized_name' => "rock n' rave disc 2",
    ]);
    $file->artists()->sync([$artist->id]);
    $file->albums()->sync([$album->id]);
    $file->metadata()->create([
        'payload' => [
            'title' => 'Bring the Noise Remix [Pump-Kin Remix]',
            'artist' => 'Benny Benassi',
            'album' => "Rock N' Rave Disc 2",
            'duration' => 398,
            'track' => '1',
            'disc' => '1',
            'label' => 'Energy',
            'year' => '2008',
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'local_ai_discogs')
        ->assertJsonPath('proposal.proposed_values.title', 'Bring The Noise Remix (Pump-kin Remix)')
        ->assertJsonPath('proposal.proposed_values.artists', ['Benny Benassi'])
        ->assertJsonPath('proposal.proposed_values.album', "Rock'N'Rave")
        ->assertJsonPath('proposal.proposed_values.track_number', '1')
        ->assertJsonPath('proposal.proposed_values.disc_number', '2')
        ->assertJsonPath('proposal.proposed_values.release_label', 'Ultra Records (16)')
        ->assertJsonPath('proposal.proposed_values.catalog_number', 'UL 1695-2')
        ->assertJsonPath('proposal.proposed_values.release_date', '2008')
        ->assertJsonPath('proposal.proposed_values.release_country', 'Russia')
        ->assertJsonPath('proposal.proposed_values.discogs_release_id', '14839269')
        ->assertJsonPath('proposal.proposed_values.cover_url', 'https://discogs.test/image/rock-n-rave-primary.jpg')
        ->assertJsonPath('proposal.evidence.ai_search_plan.0.release_title', "Rock N' Rave")
        ->assertJsonPath('proposal.evidence.ai_review.selected_track_position', '2-1')
        ->assertJsonMissingPath('proposal.proposed_values.musicbrainz_release_id');

    expect($aiSchemas)->toContain('atlas-audio-metadata-discogs-search-v1')
        ->and($aiSchemas)->toContain('atlas-audio-metadata-anomaly-v1')
        ->and($discogsSearches)->toContain([
            'release_title' => "Rock N' Rave",
            'artist' => 'Benny Benassi',
            'q' => '',
        ]);
});
