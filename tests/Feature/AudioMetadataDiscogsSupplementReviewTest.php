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

test('discogs supplement asks ai before applying conflicting release disc fields', function () {
    config([
        'services.audio_metadata.acoustid_client_key' => 'acoustid-client',
        'services.audio_metadata.acoustid_api_base_url' => 'https://acoustid.test/v2',
        'services.audio_metadata.musicbrainz_api_base_url' => 'https://musicbrainz.test',
        'services.audio_metadata.discogs_user_token' => 'discogs-token',
        'services.audio_metadata.discogs_api_base_url' => 'https://discogs.test',
        'services.audio_metadata.ai_enabled' => true,
        'services.audio_metadata.ai_driver' => 'ollama',
        'services.audio_metadata.ai_base_url' => 'https://ollama.test',
        'services.audio_metadata.ai_model' => 'qwen-test',
    ]);

    $this->mock(AudioFingerprintService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forFile')
            ->once()
            ->andReturn(new AudioFingerprint('overture-fingerprint', 148, '/tmp/overture.mp3'));
    });

    $aiCalls = 0;

    Http::fake(function (Request $request) use (&$aiCalls) {
        $url = $request->url();

        if (str_starts_with($url, 'https://acoustid.test/v2/lookup')) {
            return Http::response([
                'status' => 'ok',
                'results' => [[
                    'id' => 'acoustid-overture',
                    'score' => 1.0,
                    'recordings' => [[
                        'id' => 'overture-recording-mbid',
                        'title' => 'Overture',
                        'duration' => 148000,
                        'artists' => [
                            ['name' => 'Daft Punk'],
                        ],
                    ]],
                ]],
            ]);
        }

        if ($url === 'https://musicbrainz.test/ws/2/recording/overture-recording-mbid') {
            return Http::response([
                'id' => 'overture-recording-mbid',
                'title' => 'Overture',
                'length' => 148000,
                'artist-credit' => [
                    ['name' => 'Daft Punk', 'artist' => ['name' => 'Daft Punk']],
                ],
                'releases' => [],
            ]);
        }

        if (str_starts_with($url, 'https://musicbrainz.test/ws/2/release?')) {
            return Http::response(['releases' => []]);
        }

        if (str_starts_with($url, 'https://discogs.test/database/search')) {
            return Http::response([
                'results' => [
                    ['id' => 509994728927],
                ],
            ]);
        }

        if ($url === 'https://discogs.test/releases/509994728927') {
            return Http::response([
                'id' => 509994728927,
                'title' => 'TRON: Legacy: MetroTokyo Edition Complete Motion Picture Soundtrack',
                'country' => 'XW',
                'released' => '2013-02-28',
                'artists' => [
                    ['name' => 'Daft Punk'],
                ],
                'labels' => [
                    ['name' => '[no label]', 'catno' => '50999 9472892 7'],
                ],
                'identifiers' => [
                    ['type' => 'Barcode', 'value' => '5 099994 728927'],
                ],
                'tracklist' => [[
                    'position' => '3-51',
                    'title' => 'Overture (album version)',
                    'duration' => '2:28',
                ]],
            ]);
        }

        if ($url === 'https://ollama.test/api/chat') {
            $aiCalls++;

            return Http::response([
                'message' => [
                    'content' => json_encode([
                        'verdict' => 'reject',
                        'confidence' => 0.91,
                        'reason' => 'The recording matches, but the Discogs release is a different edition and disc from the current CD 1 album context.',
                        'model' => 'qwen-test',
                        'safe_fields' => [],
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
        'title' => 'Overture',
        'filename' => '01-overture.mp3',
    ]);
    $artist = Artist::factory()->create([
        'name' => 'Daft Punk',
        'normalized_name' => 'daft punk',
    ]);
    $album = Album::factory()->create([
        'name' => 'Tron: Legacy (Cd1)',
        'normalized_name' => 'tron legacy cd1',
    ]);
    $file->artists()->sync([$artist->id]);
    $file->albums()->sync([$album->id]);
    $file->metadata()->create([
        'payload' => [
            'duration' => 148,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal', null);

    expect($aiCalls)->toBeGreaterThanOrEqual(1);
});

test('discogs supplement asks ai before applying mismatched release album fields', function () {
    config([
        'services.audio_metadata.acoustid_client_key' => 'acoustid-client',
        'services.audio_metadata.acoustid_api_base_url' => 'https://acoustid.test/v2',
        'services.audio_metadata.musicbrainz_api_base_url' => 'https://musicbrainz.test',
        'services.audio_metadata.discogs_user_token' => 'discogs-token',
        'services.audio_metadata.discogs_api_base_url' => 'https://discogs.test',
        'services.audio_metadata.ai_enabled' => true,
        'services.audio_metadata.ai_driver' => 'ollama',
        'services.audio_metadata.ai_base_url' => 'https://ollama.test',
        'services.audio_metadata.ai_model' => 'qwen-test',
    ]);

    $this->mock(AudioFingerprintService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forFile')
            ->once()
            ->andReturn(new AudioFingerprint('hijack-fingerprint', 325, '/tmp/hijack.mp3'));
    });

    $aiCalls = 0;

    Http::fake(function (Request $request) use (&$aiCalls) {
        $url = $request->url();

        if (str_starts_with($url, 'https://acoustid.test/v2/lookup')) {
            return Http::response([
                'status' => 'ok',
                'results' => [[
                    'id' => 'acoustid-hijack',
                    'score' => 0.996,
                    'recordings' => [[
                        'id' => 'hijack-recording-mbid',
                        'title' => 'Hijack (Original Mix)',
                        'duration' => 325000,
                        'artists' => [
                            ['name' => 'Smith & Pledger'],
                            ['name' => 'Aspekt'],
                        ],
                    ]],
                ]],
            ]);
        }

        if ($url === 'https://musicbrainz.test/ws/2/recording/hijack-recording-mbid') {
            return Http::response([
                'id' => 'hijack-recording-mbid',
                'title' => 'Hijack (Original Mix)',
                'length' => 325000,
                'artist-credit' => [
                    ['name' => 'Smith & Pledger', 'artist' => ['name' => 'Smith & Pledger']],
                    ['name' => 'Aspekt', 'artist' => ['name' => 'Aspekt']],
                ],
                'releases' => [],
            ]);
        }

        if (str_starts_with($url, 'https://musicbrainz.test/ws/2/release?')) {
            return Http::response(['releases' => []]);
        }

        if (str_starts_with($url, 'https://discogs.test/database/search')) {
            return Http::response([
                'results' => [
                    ['id' => 51392],
                ],
            ]);
        }

        if ($url === 'https://discogs.test/releases/51392') {
            return Http::response([
                'id' => 51392,
                'title' => 'Anjunabeats Progressive Session',
                'country' => 'RU',
                'released' => '2005-08-20',
                'artists' => [
                    ['name' => 'Smith & Pledger'],
                    ['name' => 'Aspekt'],
                ],
                'labels' => [
                    ['name' => 'World Club Music', 'catno' => 'ПРЗ CD51392'],
                ],
                'identifiers' => [
                    ['type' => 'Barcode', 'value' => '6 17465 13212 4'],
                ],
                'tracklist' => [[
                    'position' => '5',
                    'title' => 'Hijack (Original Mix)',
                    'duration' => '5:25',
                ]],
            ]);
        }

        if ($url === 'https://ollama.test/api/chat') {
            $aiCalls++;

            return Http::response([
                'message' => [
                    'content' => json_encode([
                        'verdict' => 'reject',
                        'confidence' => 0.88,
                        'reason' => 'The track matches, but the Discogs release album is not consistent with the current Anjunabeats Volume Three collection context.',
                        'model' => 'qwen-test',
                        'safe_fields' => [],
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
        'title' => 'Hijack (Original Mix)',
        'filename' => '05-hijack-original-mix.mp3',
    ]);
    $artist = Artist::factory()->create([
        'name' => 'Smith & Pledger, Aspekt',
        'normalized_name' => 'smith pledger aspekt',
    ]);
    $album = Album::factory()->create([
        'name' => 'Anjunabeats Volume Three (Mixed By Above & Beyond)',
        'normalized_name' => 'anjunabeats volume three mixed by above beyond',
    ]);
    $file->artists()->sync([$artist->id]);
    $file->albums()->sync([$album->id]);
    $file->metadata()->create([
        'payload' => [
            'duration' => 325,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal', null);

    expect($aiCalls)->toBeGreaterThanOrEqual(1);
});
