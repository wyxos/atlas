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

test('fingerprint release drift asks ai before replacing a plausible current edition album', function () {
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
            ->andReturn(new AudioFingerprint('liquid-love-fingerprint', 452, '/tmp/liquid-love.mp3'));
    });

    $aiCalls = 0;

    Http::fake(function (Request $request) use (&$aiCalls) {
        $url = $request->url();

        if (str_starts_with($url, 'https://acoustid.test/v2/lookup')) {
            return Http::response([
                'status' => 'ok',
                'results' => [[
                    'id' => 'acoustid-liquid-love',
                    'score' => 0.955,
                    'recordings' => [[
                        'id' => 'liquid-love-recording-mbid',
                        'title' => 'Liquid Love (Tongue of God remix)',
                        'duration' => 451000,
                        'artists' => [
                            ['name' => 'Above & Beyond'],
                        ],
                        'releases' => [[
                            'id' => 'tri-state-remixed-release',
                            'title' => 'Tri-State Remixed',
                            'date' => '2007',
                            'country' => 'GB',
                        ]],
                    ]],
                ]],
            ]);
        }

        if ($url === 'https://musicbrainz.test/ws/2/recording/liquid-love-recording-mbid') {
            return Http::response([
                'id' => 'liquid-love-recording-mbid',
                'title' => 'Liquid Love (Tongue of God remix)',
                'length' => 451000,
                'artist-credit' => [
                    ['name' => 'Above & Beyond', 'artist' => ['name' => 'Above & Beyond']],
                ],
                'releases' => [[
                    'id' => 'tri-state-remixed-release',
                    'title' => 'Tri-State Remixed',
                    'date' => '2007',
                    'country' => 'GB',
                ]],
            ]);
        }

        if ($url === 'https://musicbrainz.test/ws/2/release/tri-state-remixed-release') {
            return Http::response([
                'id' => 'tri-state-remixed-release',
                'title' => 'Tri-State Remixed',
                'date' => '2007',
                'country' => 'GB',
                'media' => [[
                    'position' => 1,
                    'tracks' => [[
                        'position' => 6,
                        'number' => '6',
                        'title' => 'Liquid Love (Tongue of God remix)',
                    ]],
                ]],
            ]);
        }

        if ($url === 'https://cover.test/release/tri-state-remixed-release') {
            return Http::response([
                'images' => [[
                    'front' => true,
                    'image' => 'https://cover.test/release/tri-state-remixed-release/front.jpg',
                ]],
            ]);
        }

        if ($url === 'https://ollama.test/api/chat') {
            $aiCalls++;

            return Http::response([
                'message' => [
                    'content' => json_encode([
                        'verdict' => 'reject',
                        'confidence' => 0.86,
                        'reason' => 'The recording matches, but the proposed release is a different edition than the current 2008 Remix Edition album.',
                        'model' => 'qwen-test',
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
        'title' => 'Tongue Of God Mix',
        'filename' => '02-tongue-of-god-mix.mp3',
    ]);
    $artist = Artist::factory()->create([
        'name' => 'Liquid Love',
        'normalized_name' => 'liquid love',
    ]);
    $album = Album::factory()->create([
        'name' => 'Tri State 2008 Remix Edition',
        'normalized_name' => 'tri state 2008 remix edition',
    ]);
    $file->artists()->sync([$artist->id]);
    $file->albums()->sync([$album->id]);
    $file->metadata()->create([
        'payload' => [
            'duration' => 452,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'acoustid_musicbrainz')
        ->assertJsonPath('proposal.proposed_values', [])
        ->assertJsonPath('proposal.field_options.album.0.value', 'Tri-State Remixed')
        ->assertJsonPath('proposal.field_options.album.0.recommended', false)
        ->assertJsonPath('proposal.field_options.cover_url.0.value', 'https://cover.test/release/tri-state-remixed-release/front.jpg')
        ->assertJsonPath('proposal.field_options.cover_url.0.recommended', false);

    expect($aiCalls)->toBeGreaterThanOrEqual(1);
});

test('fingerprint release with cover does not replace a broader remix edition with a different single release', function () {
    config([
        'services.audio_metadata.acoustid_client_key' => 'acoustid-client',
        'services.audio_metadata.acoustid_api_base_url' => 'https://acoustid.test/v2',
        'services.audio_metadata.musicbrainz_api_base_url' => 'https://musicbrainz.test',
        'services.audio_metadata.cover_art_archive_base_url' => 'https://cover.test',
        'services.audio_metadata.ai_enabled' => false,
    ]);

    $this->mock(AudioFingerprintService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forFile')
            ->once()
            ->andReturn(new AudioFingerprint('home-michael-badal-fingerprint', 437, '/tmp/home-michael-badal.mp3'));
    });

    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_starts_with($url, 'https://acoustid.test/v2/lookup')) {
            return Http::response([
                'status' => 'ok',
                'results' => [[
                    'id' => 'acoustid-home-michael-badal',
                    'score' => 0.975,
                    'recordings' => [[
                        'id' => 'home-michael-badal-recording',
                        'title' => 'Home (Michael Badal remix)',
                        'duration' => 437000,
                        'artists' => [
                            ['name' => 'Above & Beyond'],
                        ],
                        'releases' => [[
                            'id' => 'home-the-remixes-release',
                            'title' => 'Home (The Remixes)',
                            'date' => '2007-10-22',
                            'country' => 'GB',
                        ]],
                    ]],
                ]],
            ]);
        }

        if ($url === 'https://musicbrainz.test/ws/2/release/home-the-remixes-release') {
            return Http::response([
                'id' => 'home-the-remixes-release',
                'title' => 'Home (The Remixes)',
                'date' => '2007-10-22',
                'country' => 'GB',
                'barcode' => '5039060110959',
                'label-info' => [[
                    'catalog-number' => 'EA 71465',
                    'label' => ['name' => 'Anjunabeats'],
                ]],
                'media' => [[
                    'position' => 1,
                    'tracks' => [[
                        'position' => 4,
                        'number' => '4',
                        'title' => 'Home (Michael Badal remix)',
                        'recording' => ['id' => 'home-michael-badal-recording'],
                    ]],
                ]],
            ]);
        }

        if ($url === 'https://cover.test/release/home-the-remixes-release') {
            return Http::response([
                'images' => [[
                    'front' => true,
                    'image' => 'https://cover.test/release/home-the-remixes-release/front.jpg',
                ]],
            ]);
        }

        return Http::response([], 404);
    });

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Michael Badal Remix',
        'filename' => '04-michael-badal-remix.mp3',
    ]);
    $artist = Artist::factory()->create([
        'name' => 'Home',
        'normalized_name' => 'home',
    ]);
    $album = Album::factory()->create([
        'name' => 'Tri State 2008 Remix Edition',
        'normalized_name' => 'tri state 2008 remix edition',
    ]);
    $file->artists()->sync([$artist->id]);
    $file->albums()->sync([$album->id]);
    $file->metadata()->create([
        'payload' => [
            'duration' => 437,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'acoustid_musicbrainz')
        ->assertJsonPath('proposal.proposed_values', [])
        ->assertJsonPath('proposal.field_options.album.0.value', 'Home (The Remixes)')
        ->assertJsonPath('proposal.field_options.album.0.recommended', false)
        ->assertJsonPath('proposal.field_options.cover_url.0.value', 'https://cover.test/release/home-the-remixes-release/front.jpg')
        ->assertJsonPath('proposal.field_options.cover_url.0.recommended', false);
});

test('fingerprint metadata lets ai restrict unsafe release fields per field', function () {
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
                    'content' => json_encode([
                        'verdict' => 'ambiguous',
                        'confidence' => 0.79,
                        'reason' => 'The fingerprint may identify the recording family, but the current title and album indicate a different Mirco De Govia vinyl/remix release context.',
                        'model' => 'qwen-test',
                        'safe_fields' => ['musicbrainz_recording_id'],
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
        ->assertJsonPath('proposal.proposed_values.musicbrainz_recording_id', 'air-for-life-recording-mbid')
        ->assertJsonPath('proposal.evidence.field_review.safe_fields', ['musicbrainz_recording_id'])
        ->assertJsonMissingPath('proposal.proposed_values.title')
        ->assertJsonMissingPath('proposal.proposed_values.artists')
        ->assertJsonMissingPath('proposal.proposed_values.album')
        ->assertJsonMissingPath('proposal.proposed_values.duration_seconds')
        ->assertJsonMissingPath('proposal.proposed_values.cover_url')
        ->assertJsonMissingPath('proposal.proposed_values.track_number')
        ->assertJsonMissingPath('proposal.proposed_values.disc_number')
        ->assertJsonMissingPath('proposal.proposed_values.release_label')
        ->assertJsonMissingPath('proposal.proposed_values.catalog_number')
        ->assertJsonMissingPath('proposal.proposed_values.release_date')
        ->assertJsonMissingPath('proposal.proposed_values.release_country')
        ->assertJsonMissingPath('proposal.proposed_values.musicbrainz_release_id');

    expect($aiCalls)->toBe(1);
});
