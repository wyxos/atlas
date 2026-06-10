<?php

use App\Models\Album;
use App\Models\Artist;
use App\Models\File;
use App\Models\User;
use App\Services\Audio\AudioFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('discogs release lookup proposes release packaging details when musicbrainz has no match', function () {
    config([
        'services.audio_metadata.discogs_user_token' => 'discogs-token',
        'services.audio_metadata.discogs_api_base_url' => 'https://discogs.test',
        'services.audio_metadata.musicbrainz_api_base_url' => 'https://musicbrainz.test',
    ]);

    $this->mock(AudioFingerprintService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forFile')
            ->once()
            ->andReturn(null);
    });

    Http::fake([
        'https://musicbrainz.test/ws/2/release?*' => Http::response(['releases' => []]),
        'https://discogs.test/database/search*' => Http::response([
            'results' => [
                ['id' => 2969820],
            ],
        ]),
        'https://discogs.test/releases/2969820' => Http::response([
            'id' => 2969820,
            'title' => 'Mysterious Times (UK-Remixes EP)',
            'country' => 'UK',
            'released' => '2011-06-23',
            'artists' => [
                ['name' => 'Sash! Feat Tina Cousins'],
            ],
            'labels' => [
                ['name' => 'Tokapi Recordings', 'catno' => 'TR011'],
            ],
            'identifiers' => [
                ['type' => 'Barcode', 'value' => '8715576130112'],
            ],
            'tracklist' => [
                [
                    'position' => '8',
                    'title' => 'Mysterious Times (Marc Lime & K Bastian Remix)',
                    'duration' => '6:23',
                ],
            ],
        ]),
    ]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Mysterious Times (Marc Lime & K Bastian Remix)',
    ]);
    $artist = Artist::factory()->create([
        'name' => 'Sash! Feat Tina Cousins',
        'normalized_name' => 'sash! feat tina cousins',
    ]);
    $album = Album::factory()->create([
        'name' => 'Mysterious Times (UK-Remixes EP)',
        'normalized_name' => 'mysterious times (uk-remixes ep)',
    ]);
    $file->artists()->sync([$artist->id]);
    $file->albums()->sync([$album->id]);
    $file->metadata()->create([
        'payload' => [
            'duration_seconds' => 383,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'discogs_release')
        ->assertJsonPath('proposal.confidence', 90)
        ->assertJsonPath('proposal.proposed_values.release_label', 'Tokapi Recordings')
        ->assertJsonPath('proposal.proposed_values.catalog_number', 'TR011')
        ->assertJsonPath('proposal.proposed_values.barcode', '8715576130112')
        ->assertJsonPath('proposal.proposed_values.release_date', '2011-06-23')
        ->assertJsonPath('proposal.proposed_values.release_country', 'UK')
        ->assertJsonPath('proposal.proposed_values.track_number', '8')
        ->assertJsonPath('proposal.proposed_values.discogs_release_id', '2969820')
        ->assertJsonPath('proposal.evidence.source', 'discogs_release_search')
        ->assertJsonPath('proposal.evidence.discogs_release_id', '2969820')
        ->assertJsonPath('proposal.evidence.track_position', '8');
});

test('discogs album fallback can propose a cover when artist script differs', function () {
    config([
        'services.audio_metadata.discogs_user_token' => 'discogs-token',
        'services.audio_metadata.discogs_api_base_url' => 'https://discogs.test',
        'services.audio_metadata.musicbrainz_api_base_url' => 'https://musicbrainz.test',
    ]);

    $this->mock(AudioFingerprintService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forFile')
            ->once()
            ->andReturn(null);
    });

    Http::fake([
        'https://musicbrainz.test/ws/2/release?*' => Http::response(['releases' => []]),
        'https://discogs.test/database/search*' => Http::sequence()
            ->push(['results' => []])
            ->push(['results' => []])
            ->push(['results' => [['id' => 17124567]]]),
        'https://discogs.test/releases/17124567' => Http::response([
            'id' => 17124567,
            'title' => 'TVアニメーション GTO オリジナルサウンドトラック = TV Animation GTO Original Soundtrack',
            'country' => 'Japan',
            'released' => '1999',
            'artists' => [
                ['name' => '本間 勇輔*'],
            ],
            'labels' => [
                ['name' => 'Aniplex', 'catno' => 'SVWC-7033'],
            ],
            'identifiers' => [
                ['type' => 'Barcode', 'value' => '4534530703321'],
            ],
            'images' => [[
                'type' => 'primary',
                'uri' => 'https://discogs.test/image/gto-primary.jpg',
                'uri150' => 'https://discogs.test/image/gto-thumb.jpg',
            ]],
            'tracklist' => [
                [
                    'position' => '1',
                    'title' => 'Theme from GTO',
                    'duration' => '3:21',
                ],
            ],
        ]),
    ]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Theme from GTO',
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
            'duration_seconds' => 201,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'discogs_release')
        ->assertJsonPath('proposal.proposed_values.album', 'TVアニメーション GTO オリジナルサウンドトラック')
        ->assertJsonPath('proposal.proposed_values.cover_url', 'https://discogs.test/image/gto-primary.jpg')
        ->assertJsonPath('proposal.proposed_values.discogs_release_id', '17124567')
        ->assertJsonPath('proposal.evidence.discogs_release_id', '17124567')
        ->assertJsonPath('proposal.evidence.cover_source', 'discogs_images')
        ->assertJsonMissingPath('proposal.proposed_values.album_aliases');
});

test('discogs lookup uses ai search terms from current metadata before local fallback', function () {
    config([
        'services.audio_metadata.discogs_user_token' => 'discogs-token',
        'services.audio_metadata.discogs_api_base_url' => 'https://discogs.test',
        'services.audio_metadata.musicbrainz_api_base_url' => 'https://musicbrainz.test',
        'services.audio_metadata.ai_enabled' => true,
        'services.audio_metadata.ai_driver' => 'gateway',
        'services.audio_metadata.ai_base_url' => 'https://ollama.test',
        'services.audio_metadata.ai_token' => 'ai-token',
        'services.audio_metadata.ai_model' => 'qwen-test',
    ]);

    $this->mock(AudioFingerprintService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forFile')->once()->andReturn(null);
    });

    $aiSchemas = [];
    $discogsSearches = [];

    Http::fake(function (Request $request) use (&$aiSchemas, &$discogsSearches) {
        $url = $request->url();

        if (str_starts_with($url, 'https://musicbrainz.test/ws/2/release?')) {
            return Http::response(['releases' => []]);
        }

        if (str_starts_with($url, 'https://discogs.test/database/search')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $discogsSearches[] = [
                'release_title' => (string) ($query['release_title'] ?? ''),
                'artist' => (string) ($query['artist'] ?? ''),
            ];

            $matchesAiQuery = ($query['release_title'] ?? null) === 'Floating World'
                && ($query['artist'] ?? null) === 'Fast Distance & Static Blue';

            return Http::response([
                'results' => $matchesAiQuery ? [['id' => 1423078, 'type' => 'release']] : [],
            ]);
        }

        if ($url === 'https://discogs.test/releases/1423078') {
            return Http::response([
                'id' => 1423078,
                'title' => 'Floating World',
                'country' => 'UK',
                'released' => '2008',
                'artists' => [
                    ['name' => 'Fast Distance & Static Blue'],
                ],
                'labels' => [
                    ['name' => 'Alter Ego Records', 'catno' => 'AER012'],
                ],
                'images' => [[
                    'type' => 'primary',
                    'uri' => 'https://discogs.test/image/floating-world.jpg',
                ]],
                'tracklist' => [[
                    'position' => '4',
                    'title' => 'Floating World (Haris C Remix)',
                    'duration' => '7:52',
                ]],
            ]);
        }

        if ($url === 'https://ollama.test/v1/audio/metadata-review') {
            $schema = (string) ($request->data()['schemaVersion'] ?? '');
            $aiSchemas[] = $schema;

            return Http::response($schema === 'atlas-audio-metadata-discogs-search-v1'
                ? [
                    'queries' => [[
                        'release_title' => 'Floating World',
                        'artist' => 'Fast Distance & Static Blue',
                        'reason' => 'Discogs uses ampersand artist credit for this collaboration.',
                    ]],
                    'model' => 'qwen-test',
                ]
                : [
                    'verdict' => 'accept',
                    'confidence' => 0.94,
                    'reason' => 'Discogs release matches the current title, album, artist collaboration, and duration.',
                    'model' => 'qwen-test',
                    'safe_fields' => [
                        'cover_url',
                        'track_number',
                        'release_label',
                        'catalog_number',
                        'release_date',
                        'release_country',
                        'discogs_release_id',
                    ],
                ]);
        }

        return Http::response([], 404);
    });

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Floating World (Haris C Remix)',
    ]);
    $artist = Artist::factory()->create([
        'name' => 'Fast Distance And Static Blue',
        'normalized_name' => 'fast distance and static blue',
    ]);
    $album = Album::factory()->create([
        'name' => 'Floating World',
        'normalized_name' => 'floating world',
    ]);
    $file->artists()->sync([$artist->id]);
    $file->albums()->sync([$album->id]);
    $file->metadata()->create([
        'payload' => [
            'duration_seconds' => 472,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'discogs_release')
        ->assertJsonPath('proposal.proposed_values.track_number', '4')
        ->assertJsonPath('proposal.proposed_values.release_label', 'Alter Ego Records')
        ->assertJsonPath('proposal.proposed_values.catalog_number', 'AER012')
        ->assertJsonPath('proposal.proposed_values.release_date', '2008')
        ->assertJsonPath('proposal.proposed_values.release_country', 'UK')
        ->assertJsonPath('proposal.proposed_values.discogs_release_id', '1423078')
        ->assertJsonPath('proposal.proposed_values.cover_url', 'https://discogs.test/image/floating-world.jpg');

    expect($aiSchemas)->toContain('atlas-audio-metadata-discogs-search-v1')
        ->and($discogsSearches)->toContain([
            'release_title' => 'Floating World',
            'artist' => 'Fast Distance & Static Blue',
        ]);
});
