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

test('local embedded tags can use ai discogs search expansion to propose source release cover', function () {
    config([
        'services.audio_metadata.acoustid_client_key' => 'acoustid-client',
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
        $mock->shouldReceive('forFile')->once()->andReturn(null);
    });

    $aiCalls = 0;
    $discogsSearches = [];

    Http::fake(function (Request $request) use (&$aiCalls, &$discogsSearches) {
        $url = $request->url();

        if (str_starts_with($url, 'https://musicbrainz.test/ws/2/release?')) {
            return Http::response(['releases' => []]);
        }

        if (str_starts_with($url, 'https://discogs.test/database/search')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $discogsSearches[] = [
                'release_title' => (string) ($query['release_title'] ?? ''),
                'artist' => (string) ($query['artist'] ?? ''),
                'q' => (string) ($query['q'] ?? ''),
            ];

            $matchesAiQuery = ($query['release_title'] ?? null) === 'GTO TV Animation Original Soundtrack 2'
                && ($query['artist'] ?? null) === 'Yusuke Homma';

            return Http::response([
                'results' => $matchesAiQuery ? [['id' => 19442]] : [],
            ]);
        }

        if ($url === 'https://discogs.test/releases/19442') {
            return Http::response([
                'id' => 19442,
                'title' => 'TVアニメーション GTO オリジナルサウンドトラック2 = GTO TV Animation Original Soundtrack 2',
                'country' => 'Japan',
                'released' => '2000-07-05',
                'artists' => [
                    ['name' => '本間勇輔'],
                ],
                'labels' => [
                    ['name' => 'SPE Visual Works', 'catno' => 'SVWC-1309'],
                ],
                'identifiers' => [
                    ['type' => 'Barcode', 'value' => '4534530130945'],
                ],
                'images' => [[
                    'type' => 'primary',
                    'uri' => 'https://discogs.test/image/gto-2-official.jpg',
                    'uri150' => 'https://discogs.test/image/gto-2-official-thumb.jpg',
                ]],
                'tracklist' => [[
                    'position' => '10',
                    'title' => 'ONIZUKA暴発へのプロローグ',
                    'duration' => '',
                ]],
            ]);
        }

        if ($url === 'https://ollama.test/v1/audio/metadata-review') {
            $aiCalls++;
            $schema = $request->data()['schemaVersion'] ?? null;

            return Http::response($schema === 'atlas-audio-metadata-discogs-search-v1'
                ? [
                    'queries' => [[
                        'release_title' => 'GTO TV Animation Original Soundtrack 2',
                        'artist' => 'Yusuke Honma',
                        'reason' => 'Matches current album and embedded artist spelling.',
                    ]],
                    'model' => 'qwen-test',
                ]
                : [
                    'verdict' => 'accept',
                    'confidence' => 0.86,
                    'reason' => 'The local tags and Discogs track represent the same soundtrack cue.',
                    'model' => 'qwen-test',
                    'source_identity_supported' => true,
                    'selected_track_position' => '10',
                    'selected_track_title' => 'ONIZUKA暴発へのプロローグ',
                    'title_aliases' => ['Onizuka Bouhatsu E No Prologue'],
                    'artist_aliases' => ['Yusuke Honma', 'Yusuke Homma'],
                    'album_aliases' => ['GTO TV Animation Original Soundtrack 2'],
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
            'title' => 'Onizuka Bouhatsu E No Prologue',
            'artist' => 'Yusuke Honma',
            'album' => 'GTO TV Animation Original Soundtrack 2',
            'duration' => 100,
            'track' => '10',
            'year' => '2000',
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'local_ai_discogs')
        ->assertJsonPath('proposal.proposed_values.title', 'ONIZUKA暴発へのプロローグ')
        ->assertJsonPath('proposal.proposed_values.artists', ['本間勇輔'])
        ->assertJsonPath('proposal.proposed_values.album', 'TVアニメーション GTO オリジナルサウンドトラック2')
        ->assertJsonPath('proposal.proposed_values.track_number', '10')
        ->assertJsonPath('proposal.proposed_values.release_label', 'SPE Visual Works')
        ->assertJsonPath('proposal.proposed_values.catalog_number', 'SVWC-1309')
        ->assertJsonPath('proposal.proposed_values.barcode', '4534530130945')
        ->assertJsonPath('proposal.proposed_values.release_date', '2000-07-05')
        ->assertJsonPath('proposal.proposed_values.release_country', 'Japan')
        ->assertJsonPath('proposal.proposed_values.discogs_release_id', '19442')
        ->assertJsonPath('proposal.proposed_values.cover_url', 'https://discogs.test/image/gto-2-official.jpg')
        ->assertJsonPath('proposal.evidence.ai_search_plan.0.release_title', 'GTO TV Animation Original Soundtrack 2')
        ->assertJsonPath('proposal.evidence.ai_search_plan.0.artist', 'Yusuke Homma')
        ->assertJsonPath('proposal.evidence.ai_review.selected_track_position', '10')
        ->assertJsonMissingPath('proposal.proposed_values.title_aliases')
        ->assertJsonMissingPath('proposal.proposed_values.album_aliases');

    expect($aiCalls)->toBe(2)
        ->and($discogsSearches)->toContain([
            'release_title' => 'GTO TV Animation Original Soundtrack 2',
            'artist' => 'Yusuke Homma',
            'q' => '',
        ]);
});

test('local ai discogs rejects later alternate releases when current soundtrack points to original year', function () {
    config([
        'services.audio_metadata.acoustid_client_key' => 'acoustid-client',
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
        $mock->shouldReceive('forFile')->once()->andReturn(null);
    });

    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_starts_with($url, 'https://musicbrainz.test/ws/2/release?')) {
            return Http::response(['releases' => []]);
        }

        if (str_starts_with($url, 'https://discogs.test/database/search')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $matchesAlternateRelease = ($query['release_title'] ?? null) === 'GTO Original Soundtrack 2'
                && ($query['artist'] ?? null) === 'Yusuke Homma';

            return Http::response([
                'results' => $matchesAlternateRelease ? [['id' => 14651911]] : [],
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
                'tracklist' => [[
                    'position' => '10',
                    'title' => 'Onizuka暴発へのプロローグ',
                    'duration' => '',
                ]],
            ]);
        }

        if ($url === 'https://ollama.test/v1/audio/metadata-review') {
            $schema = $request->data()['schemaVersion'] ?? null;

            return Http::response($schema === 'atlas-audio-metadata-discogs-search-v1'
                ? [
                    'queries' => [[
                        'release_title' => 'GTO TV Animation Original Soundtrack 2',
                        'artist' => 'Yusuke Honma',
                        'reason' => 'Matches current album and embedded artist spelling.',
                    ]],
                    'model' => 'qwen-test',
                ]
                : [
                    'verdict' => 'accept',
                    'confidence' => 0.86,
                    'reason' => 'The alternate release has the same track.',
                    'model' => 'qwen-test',
                    'source_identity_supported' => true,
                    'selected_track_position' => '10',
                    'selected_track_title' => 'Onizuka暴発へのプロローグ',
                    'title_aliases' => ['Onizuka Bouhatsu E No Prologue'],
                    'artist_aliases' => ['Yusuke Honma', 'Yusuke Homma'],
                    'album_aliases' => ['GTO TV Animation Original Soundtrack 2'],
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
            'title' => 'Onizuka Bouhatsu E No Prologue',
            'artist' => 'Yusuke Honma',
            'album' => 'GTO TV Animation Original Soundtrack 2',
            'duration' => 100,
            'track' => '10',
            'year' => '2000',
        ],
    ]);

    $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs")
        ->assertAccepted()
        ->assertJsonPath('proposal.provider', 'local')
        ->assertJsonPath('proposal.proposed_values.album', 'GTO TV Animation Original Soundtrack 2')
        ->assertJsonMissingPath('proposal.proposed_values.discogs_release_id')
        ->assertJsonMissingPath('proposal.proposed_values.release_label')
        ->assertJsonMissingPath('proposal.proposed_values.catalog_number')
        ->assertJsonMissingPath('proposal.proposed_values.cover_url');
});

test('local ai discogs rejects track-title singles for current soundtrack albums', function () {
    config([
        'services.audio_metadata.acoustid_client_key' => 'acoustid-client',
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
        $mock->shouldReceive('forFile')->once()->andReturn(null);
    });

    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_starts_with($url, 'https://musicbrainz.test/ws/2/release?')) {
            return Http::response(['releases' => []]);
        }

        if (str_starts_with($url, 'https://discogs.test/database/search')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $matchesSingleQuery = ($query['release_title'] ?? null) === 'しずく'
                && ($query['artist'] ?? null) === 'Miwako Okuda';

            return Http::response([
                'results' => $matchesSingleQuery ? [['id' => 10059896]] : [],
            ]);
        }

        if ($url === 'https://discogs.test/releases/10059896') {
            return Http::response([
                'id' => 10059896,
                'title' => 'しずく',
                'country' => 'Japan',
                'released' => '2000-02-02',
                'artists' => [
                    ['name' => 'Miwako Okuda'],
                ],
                'labels' => [
                    ['name' => 'Sony Records', 'catno' => 'SRCL-4725'],
                ],
                'identifiers' => [
                    ['type' => 'Barcode', 'value' => '4 988009 472591'],
                ],
                'images' => [[
                    'type' => 'primary',
                    'uri' => 'https://discogs.test/image/shizuku-single.jpg',
                ]],
                'tracklist' => [[
                    'position' => '1',
                    'title' => 'しずく',
                    'duration' => '',
                ]],
            ]);
        }

        if ($url === 'https://ollama.test/v1/audio/metadata-review') {
            $schema = $request->data()['schemaVersion'] ?? null;

            return Http::response($schema === 'atlas-audio-metadata-discogs-search-v1'
                ? [
                    'queries' => [[
                        'release_title' => 'しずく',
                        'artist' => 'Miwako Okuda',
                        'reason' => 'Track title single search.',
                    ]],
                    'model' => 'qwen-test',
                ]
                : [
                    'verdict' => 'accept',
                    'confidence' => 0.86,
                    'reason' => 'The single has the same title and artist.',
                    'model' => 'qwen-test',
                    'source_identity_supported' => true,
                    'selected_track_position' => '1',
                    'selected_track_title' => 'しずく',
                    'title_aliases' => ['Shizuku'],
                    'album_aliases' => ['GTO TV Animation Original Soundtrack 2'],
                ]);
        }

        return Http::response([], 404);
    });

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Shizuku',
        'filename' => 'shizuku.mp3',
    ]);
    $artist = Artist::factory()->create([
        'name' => 'Miwako Okuda',
        'normalized_name' => 'miwako okuda',
    ]);
    $album = Album::factory()->create([
        'name' => 'GTO TV Animation Original Soundtrack 2',
        'normalized_name' => 'gto tv animation original soundtrack 2',
    ]);
    $file->artists()->sync([$artist->id]);
    $file->albums()->sync([$album->id]);
    $file->metadata()->create([
        'payload' => [
            'title' => 'Shizuku',
            'artist' => 'Miwako Okuda',
            'album' => 'GTO TV Animation Original Soundtrack 2',
        ],
    ]);

    $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs")
        ->assertAccepted()
        ->assertJsonPath('proposal', null);
});
