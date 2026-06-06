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
        ->assertJsonPath('proposal', null);

    expect($aiCalls)->toBeGreaterThanOrEqual(1);
});
