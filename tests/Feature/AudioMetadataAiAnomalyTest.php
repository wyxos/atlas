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

test('local ai can bridge fingerprint identity to discogs original language metadata', function () {
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
            ->andReturn(new AudioFingerprint('bike-investigation-fingerprint', 374, '/tmp/bike.mp3'));
    });

    Http::fake([
        'https://acoustid.test/v2/lookup*' => Http::response([
            'status' => 'ok',
            'results' => [[
                'id' => 'acoustid-bike-investigation',
                'score' => 0.996,
                'recordings' => [[
                    'id' => 'bike-recording-mbid',
                    'title' => 'Bike Investigation',
                    'duration' => 373000,
                    'artists' => [
                        ['name' => '本間勇輔'],
                    ],
                ]],
            ]],
        ]),
        'https://musicbrainz.test/ws/2/release?*' => Http::response(['releases' => []]),
        'https://discogs.test/database/search*' => Http::response([
            'results' => [
                ['id' => 17124567],
            ],
        ]),
        'https://discogs.test/releases/17124567' => Http::response([
            'id' => 17124567,
            'title' => 'TVアニメーション GTO オリジナルサウンドトラック = TV Animation GTO Original Soundtrack',
            'country' => 'Japan',
            'released' => '1999-10-21',
            'artists' => [
                ['name' => 'Yusuke Homma'],
            ],
            'labels' => [
                ['name' => 'SPE Visual Works', 'catno' => 'SVWC 1405'],
            ],
            'identifiers' => [
                ['type' => 'Barcode', 'value' => '4534530140548'],
            ],
            'tracklist' => [
                [
                    'position' => '1',
                    'title' => 'The Theme From GTO',
                    'duration' => '',
                ],
                [
                    'position' => '2',
                    'title' => 'チャリンコ大捜査線',
                    'duration' => '',
                ],
            ],
        ]),
        'https://ollama.test/api/chat' => Http::response([
            'message' => [
                'content' => json_encode([
                    'verdict' => 'accept',
                    'confidence' => 0.88,
                    'reason' => 'The English current title is an alias for the Japanese Discogs track.',
                    'model' => 'qwen-test',
                    'source_identity_supported' => true,
                    'selected_track_position' => '2',
                    'selected_track_title' => 'チャリンコ大捜査線',
                    'title_aliases' => ['Bike Investigation'],
                    'artist_aliases' => ['Yusuke Honma', 'Yusuke Homma'],
                    'album_aliases' => [
                        'GTO TV Animation Original Soundtrack',
                        'TV Animation GTO Original Soundtrack',
                    ],
                ]),
            ],
        ]),
    ]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Bike Investigation',
        'filename' => 'bike-investigation.mp3',
    ]);
    $artist = Artist::factory()->create([
        'name' => 'Yusuke Honma',
        'normalized_name' => 'yusuke honma',
    ]);
    $album = Album::factory()->create([
        'name' => 'GTO TV Animation Original Soundtrack',
        'normalized_name' => 'gto tv animation original soundtrack',
    ]);
    $file->artists()->sync([$artist->id]);
    $file->albums()->sync([$album->id]);
    $file->metadata()->create([
        'payload' => [
            'duration' => 374,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'acoustid_musicbrainz_ai_discogs')
        ->assertJsonPath('proposal.confidence', 96)
        ->assertJsonPath('proposal.proposed_values.title', 'チャリンコ大捜査線')
        ->assertJsonPath('proposal.proposed_values.artists', ['本間勇輔'])
        ->assertJsonPath('proposal.proposed_values.album', 'TVアニメーション GTO オリジナルサウンドトラック')
        ->assertJsonPath('proposal.proposed_values.track_number', '2')
        ->assertJsonPath('proposal.proposed_values.release_label', 'SPE Visual Works')
        ->assertJsonPath('proposal.proposed_values.catalog_number', 'SVWC 1405')
        ->assertJsonPath('proposal.proposed_values.barcode', '4534530140548')
        ->assertJsonPath('proposal.proposed_values.release_date', '1999-10-21')
        ->assertJsonPath('proposal.proposed_values.release_country', 'Japan')
        ->assertJsonPath('proposal.proposed_values.discogs_release_id', '17124567')
        ->assertJsonPath('proposal.evidence.ai_review.verdict', 'accept')
        ->assertJsonPath('proposal.evidence.ai_review.model', 'qwen-test')
        ->assertJsonPath('proposal.evidence.ai_review.selected_track_position', '2')
        ->assertJsonPath('proposal.evidence.discogs_release_id', '17124567')
        ->assertJsonMissingPath('proposal.proposed_values.title_aliases')
        ->assertJsonMissingPath('proposal.proposed_values.artist_aliases')
        ->assertJsonMissingPath('proposal.proposed_values.album_aliases');
});

test('local ai can expand discogs search queries before reviewing a release anomaly', function () {
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
            ->andReturn(new AudioFingerprint('onizuka-fingerprint', 100, '/tmp/onizuka.mp3'));
    });

    $aiCalls = 0;
    $discogsSearches = [];

    Http::fake(function (Request $request) use (&$aiCalls, &$discogsSearches) {
        $url = $request->url();

        if (str_starts_with($url, 'https://acoustid.test/v2/lookup')) {
            return Http::response([
                'status' => 'ok',
                'results' => [[
                    'id' => 'acoustid-onizuka',
                    'score' => 0.999,
                    'recordings' => [[
                        'id' => 'onizuka-recording-mbid',
                        'title' => 'ONIZUKA暴発へのプロローグ',
                        'duration' => 100000,
                        'artists' => [
                            ['name' => '本間勇輔'],
                        ],
                    ]],
                ]],
            ]);
        }

        if (str_starts_with($url, 'https://musicbrainz.test/ws/2/release?')) {
            return Http::response(['releases' => []]);
        }

        if (str_starts_with($url, 'https://discogs.test/database/search')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $releaseTitle = (string) ($query['release_title'] ?? '');
            $artist = (string) ($query['artist'] ?? '');
            $freeText = (string) ($query['q'] ?? '');
            $discogsSearches[] = compact('releaseTitle', 'artist', 'freeText');

            $matchesAiVariant = $releaseTitle === 'GTO Original Soundtrack 2'
                && $artist === 'Yusuke Homma';

            return Http::response([
                'results' => $matchesAiVariant ? [['id' => 14651911]] : [],
            ]);
        }

        if ($url === 'https://discogs.test/releases/14651911') {
            return Http::response([
                'id' => 14651911,
                'title' => 'GTO Original Soundtrack 2',
                'country' => 'Taiwan',
                'released' => '2003',
                'artists' => [
                    ['name' => 'Yusuke Homma'],
                ],
                'labels' => [
                    ['name' => 'Miya Records', 'catno' => 'MICA-0044'],
                ],
                'images' => [[
                    'type' => 'primary',
                    'uri' => 'https://discogs.test/image/gto-2-primary.jpg',
                    'uri150' => 'https://discogs.test/image/gto-2-thumb.jpg',
                ]],
                'tracklist' => [
                    [
                        'position' => '10',
                        'title' => 'Onizuka暴発へのプロローグ',
                        'duration' => '',
                    ],
                ],
            ]);
        }

        if ($url === 'https://ollama.test/api/chat') {
            $aiCalls++;

            return Http::response([
                'message' => [
                    'content' => json_encode($aiCalls === 1
                        ? [
                            'queries' => [
                                [
                                    'release_title' => 'GTO Original Soundtrack 2',
                                    'artist' => 'Yusuke Homma',
                                    'reason' => 'Discogs may use the shortened English release title and Homma spelling.',
                                ],
                            ],
                            'model' => 'qwen-test',
                        ]
                        : [
                            'verdict' => 'accept',
                            'confidence' => 0.86,
                            'reason' => 'The fingerprint title and Discogs track represent the same GTO soundtrack cue.',
                            'model' => 'qwen-test',
                            'source_identity_supported' => true,
                            'selected_track_position' => '10',
                            'selected_track_title' => 'Onizuka暴発へのプロローグ',
                            'safe_fields' => [
                                'title',
                                'album',
                                'track_number',
                                'release_label',
                                'catalog_number',
                                'release_date',
                                'release_country',
                                'discogs_release_id',
                                'cover_url',
                            ],
                            'title_aliases' => ['Onizuka Bouhatsu E No Prologue'],
                            'artist_aliases' => ['Yusuke Honma', 'Yusuke Homma'],
                            'album_aliases' => ['GTO TV Animation Original Soundtrack 2'],
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
        'title' => 'Onizuka Bouhatsu E No Prologue',
        'filename' => 'onizuka-bouhatsu-e-no-prologue.mp3',
    ]);
    $artist = Artist::factory()->create([
        'name' => '本間勇輔',
        'normalized_name' => '本間勇輔',
    ]);
    $album = Album::factory()->create([
        'name' => 'GTO TV Animation Original Soundtrack 2',
        'normalized_name' => 'gto tv animation original soundtrack 2',
    ]);
    $file->artists()->sync([$artist->id]);
    $file->albums()->sync([$album->id]);
    $file->metadata()->create([
        'payload' => [
            'duration' => 100,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'multi_source_review')
        ->assertJsonPath('proposal.proposed_values.title', 'ONIZUKA暴発へのプロローグ')
        ->assertJsonPath('proposal.proposed_values.album', 'GTO Original Soundtrack 2')
        ->assertJsonPath('proposal.proposed_values.track_number', '10')
        ->assertJsonPath('proposal.proposed_values.release_label', 'Miya Records')
        ->assertJsonPath('proposal.proposed_values.catalog_number', 'MICA-0044')
        ->assertJsonPath('proposal.proposed_values.release_date', '2003')
        ->assertJsonPath('proposal.proposed_values.release_country', 'Taiwan')
        ->assertJsonPath('proposal.proposed_values.discogs_release_id', '14651911')
        ->assertJsonPath('proposal.proposed_values.cover_url', 'https://discogs.test/image/gto-2-primary.jpg')
        ->assertJsonPath('proposal.field_options.album.0.source_url', 'https://www.discogs.com/release/14651911')
        ->assertJsonPath('proposal.evidence.provider_candidates.1.discogs_release_id', '14651911')
        ->assertJsonMissingPath('proposal.proposed_values.title_aliases')
        ->assertJsonMissingPath('proposal.proposed_values.album_aliases');

    expect($aiCalls)->toBeGreaterThanOrEqual(3)
        ->and($discogsSearches)->toContain([
            'releaseTitle' => 'GTO Original Soundtrack 2',
            'artist' => 'Yusuke Homma',
            'freeText' => '',
        ]);
});

test('local ai discogs rejects alternate compilations when current album family is stronger evidence', function () {
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

    Http::fake(function (Request $request) {
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
                        'releases' => [[
                            'id' => 'anjunabeats-volume-three-mbid',
                            'title' => 'Anjunabeats Volume Three (Mixed By Above & Beyond)',
                            'status' => 'Official',
                            'artist-credit' => [[
                                'name' => 'Above & Beyond',
                                'artist' => ['name' => 'Above & Beyond'],
                            ]],
                        ]],
                    ]],
                ]],
            ]);
        }

        if ($url === 'https://musicbrainz.test/ws/2/release/anjunabeats-volume-three-mbid') {
            return Http::response([
                'id' => 'anjunabeats-volume-three-mbid',
                'title' => 'Anjunabeats Volume Three (Mixed By Above & Beyond)',
                'date' => '2005',
                'country' => 'GB',
                'media' => [[
                    'position' => 1,
                    'tracks' => [[
                        'position' => '5',
                        'number' => '5',
                        'title' => 'Hijack (Original Mix)',
                        'recording' => ['id' => 'hijack-recording-mbid'],
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
                    ['name' => 'Various'],
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
                    'artists' => [
                        ['name' => 'Smith & Pledger'],
                        ['name' => 'Aspekt'],
                    ],
                ]],
            ]);
        }

        if ($url === 'https://ollama.test/api/chat') {
            return Http::response([
                'message' => [
                    'content' => json_encode([
                        'verdict' => 'accept',
                        'confidence' => 0.88,
                        'reason' => 'The alternate compilation contains the same track and duration.',
                        'model' => 'qwen-test',
                        'source_identity_supported' => false,
                        'selected_track_position' => '5',
                        'selected_track_title' => 'Hijack (Original Mix)',
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
        'filename' => 'hijack-original-mix.mp3',
    ]);
    $artist = Artist::factory()->create([
        'name' => 'Smith & Pledger Pres. Aspekt',
        'normalized_name' => 'smith & pledger pres. aspekt',
    ]);
    $album = Album::factory()->create([
        'name' => 'Anjunabeats Volume Three (Mixed By Above & Beyond)',
        'normalized_name' => 'anjunabeats volume three (mixed by above & beyond)',
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
        ->assertJsonPath('proposal.provider', 'acoustid_musicbrainz')
        ->assertJsonPath('proposal.proposed_values.album', 'Anjunabeats Volume Three (Mixed By Above & Beyond)')
        ->assertJsonPath('proposal.proposed_values.musicbrainz_release_id', 'anjunabeats-volume-three-mbid')
        ->assertJsonMissingPath('proposal.proposed_values.discogs_release_id')
        ->assertJsonMissingPath('proposal.proposed_values.release_label')
        ->assertJsonMissingPath('proposal.proposed_values.catalog_number')
        ->assertJsonMissingPath('proposal.proposed_values.barcode')
        ->assertJsonMissingPath('proposal.proposed_values.release_country');
});
