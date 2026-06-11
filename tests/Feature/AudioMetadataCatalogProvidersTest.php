<?php

use App\Models\File;
use App\Models\SpotifyToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('local metadata run includes connected catalog providers as lookup candidates', function () {
    config([
        'services.audio_metadata.fingerprinting_enabled' => false,
        'services.audio_metadata.vgmdb_enabled' => false,
        'services.audio_metadata.spotify_catalog_enabled' => true,
        'services.audio_metadata.apple_enabled' => true,
        'services.audio_metadata.apple_api_base_url' => 'https://itunes.test',
        'services.audio_metadata.deezer_enabled' => true,
        'services.audio_metadata.deezer_api_base_url' => 'https://deezer.test',
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
        'https://spotify.test/v1/search*' => Http::response([
            'tracks' => [
                'items' => [[
                    'name' => 'SOULTAKER',
                    'uri' => 'spotify:track:1WUjJPUznd8NJVdzqUpT1M',
                    'duration_ms' => 266000,
                    'external_ids' => ['isrc' => 'JPVIC0100010'],
                    'track_number' => 2,
                    'disc_number' => 1,
                    'artists' => [
                        ['name' => 'JAM Project'],
                    ],
                    'album' => [
                        'name' => 'The SoulTaker Original Soundtrack',
                        'release_date' => '2001-04-21',
                        'images' => [
                            ['url' => 'https://spotify.test/cover-small.jpg', 'width' => 64],
                            ['url' => 'https://spotify.test/cover-large.jpg', 'width' => 640],
                        ],
                    ],
                ]],
            ],
        ]),
        'https://itunes.test/search*' => Http::response([
            'resultCount' => 1,
            'results' => [[
                'wrapperType' => 'track',
                'kind' => 'song',
                'trackName' => 'SOULTAKER',
                'artistName' => 'JAM Project',
                'collectionName' => 'The SoulTaker Original Soundtrack',
                'trackTimeMillis' => 266000,
                'artworkUrl100' => 'https://itunes.test/cover-100.jpg',
                'trackNumber' => 2,
                'discNumber' => 1,
                'releaseDate' => '2001-04-21T12:00:00Z',
                'trackViewUrl' => 'https://music.apple.com/album/the-soultaker/123?i=456',
                'collectionViewUrl' => 'https://music.apple.com/album/the-soultaker/123',
            ]],
        ]),
        'https://deezer.test/search/track*' => Http::response([
            'data' => [[
                'id' => 98765,
                'title' => 'SOULTAKER',
                'duration' => 266,
                'isrc' => 'JPVIC0100010',
                'track_position' => 2,
                'disk_number' => 1,
                'link' => 'https://www.deezer.com/track/98765',
                'artist' => [
                    'name' => 'JAM Project',
                ],
                'album' => [
                    'id' => 12345,
                    'title' => 'The SoulTaker Original Soundtrack',
                    'cover_xl' => 'https://deezer.test/cover-xl.jpg',
                ],
            ]],
        ]),
    ]);

    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'The Soultaker',
        'filename' => 'The Soultaker.mp3',
    ]);
    $file->metadata()->create([
        'payload' => [
            'duration_seconds' => 266,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted();

    $providers = collect($response->json('proposal.evidence.provider_candidates'))
        ->pluck('provider')
        ->all();

    expect($providers)->toContain('spotify_catalog', 'apple_music', 'deezer');
});
