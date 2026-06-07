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

test('production bring the noise lookup prefers an album release with matching track and duration over a standalone remix single', function () {
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
    $badAlbumSearchedBeforeTitle = false;

    Http::fake(function (Request $request) use (&$aiSchemas, &$badAlbumSearchedBeforeTitle, &$discogsSearches) {
        $url = $request->url();

        if (str_starts_with($url, 'https://musicbrainz.test/ws/2/release?')) {
            return Http::response(['releases' => []]);
        }

        if (str_starts_with($url, 'https://discogs.test/database/search')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $discogsSearches[] = [
                'type' => (string) ($query['type'] ?? ''),
                'release_title' => (string) ($query['release_title'] ?? ''),
                'artist' => (string) ($query['artist'] ?? ''),
                'q' => (string) ($query['q'] ?? ''),
            ];

            if (($query['release_title'] ?? null) === "Rock N' Rave Disc 2") {
                $badAlbumSearchedBeforeTitle = true;
            }

            $matchesProductionAlbumRelease = ($query['type'] ?? null) === 'release'
                && ($query['release_title'] ?? null) === 'Bring the Noise Remix [Pump-Kin Remix]'
                && ($query['artist'] ?? null) === 'Benny Benassi';
            $matchesMaster = ($query['type'] ?? null) === 'master'
                && ($query['release_title'] ?? null) === 'Bring the Noise Remix'
                && ($query['artist'] ?? null) === 'Public Enemy'
                && $badAlbumSearchedBeforeTitle === false;

            return Http::response([
                'results' => [
                    ...($matchesProductionAlbumRelease ? [[
                        'id' => 1358093,
                        'type' => 'release',
                        'title' => "Benny Benassi - Rock'N'Rave",
                    ]] : []),
                    ...($matchesMaster ? [[
                        'id' => 2006623,
                        'type' => 'master',
                        'master_id' => 2006623,
                        'master_url' => 'https://discogs.test/masters/2006623',
                        'title' => 'Public Enemy vs. Benny Benassi - Bring The Noise (Remix)',
                    ]] : []),
                ],
            ]);
        }

        if ($url === 'https://discogs.test/masters/2006623') {
            return Http::response([
                'id' => 2006623,
                'main_release' => 3259171,
                'uri' => 'https://www.discogs.com/master/2006623-Public-Enemy-vs-Benny-Benassi-Bring-The-Noise-Remix',
                'title' => 'Bring The Noise (Remix)',
            ]);
        }

        if (str_starts_with($url, 'https://discogs.test/masters/2006623/versions')) {
            return Http::response([
                'versions' => [
                    ['id' => 3259171, 'resource_url' => 'https://discogs.test/releases/3259171'],
                    ['id' => 1047849, 'resource_url' => 'https://discogs.test/releases/1047849'],
                    ['id' => 1120943, 'resource_url' => 'https://discogs.test/releases/1120943'],
                ],
            ]);
        }

        if ($url === 'https://discogs.test/releases/1358093') {
            return Http::response([
                'id' => 1358093,
                'master_id' => 92414,
                'uri' => 'https://www.discogs.com/release/1358093-Benny-Benassi-RockNRave',
                'title' => "Rock'N'Rave",
                'country' => 'US',
                'released' => '2008-06-03',
                'artists' => [
                    ['name' => 'Benny Benassi'],
                ],
                'labels' => [
                    ['name' => 'Ultra Records', 'catno' => 'UL 1695-2'],
                ],
                'identifiers' => [
                    ['type' => 'Barcode', 'value' => '6 17465 16952 6'],
                ],
                'images' => [[
                    'type' => 'primary',
                    'uri' => 'https://discogs.test/image/rock-n-rave.jpg',
                ]],
                'tracklist' => [[
                    'position' => '2.1',
                    'title' => 'Bring The Noise Remix (Pump-kin Remix)',
                    'duration' => '6:38',
                    'artists' => [
                        ['name' => 'Benny Benassi'],
                        ['name' => 'Public Enemy'],
                    ],
                ]],
            ]);
        }

        if ($url === 'https://discogs.test/releases/3259171') {
            return Http::response([
                'id' => 3259171,
                'master_id' => 2006623,
                'uri' => 'https://www.discogs.com/release/3259171-Public-Enemy-vs-Benny-Benassi-Bring-The-Noise-Remix',
                'title' => 'Bring The Noise (Remix)',
                'country' => 'France',
                'released' => '2007',
                'artists' => [
                    ['name' => 'Public Enemy'],
                    ['name' => 'Benny Benassi'],
                ],
                'images' => [[
                    'type' => 'primary',
                    'uri' => 'https://discogs.test/image/bring-the-noise-edit.jpg',
                ]],
                'tracklist' => [[
                    'position' => '1',
                    'title' => 'Bring The Noise (Benny Benassi Pump-Kin Edit)',
                    'duration' => '3:37',
                ]],
            ]);
        }

        if ($url === 'https://discogs.test/releases/1047849') {
            return Http::response([
                'id' => 1047849,
                'master_id' => 2006623,
                'uri' => 'https://www.discogs.com/release/1047849-Public-Enemy-Vs-Benny-Benassi-Bring-The-Noise-Remix',
                'title' => 'Bring The Noise (Remix)',
                'country' => 'UK',
                'released' => '2007',
                'artists' => [
                    ['name' => 'Public Enemy'],
                    ['name' => 'Benny Benassi'],
                ],
                'labels' => [
                    ['name' => 'Data Records', 'catno' => 'DATA DJ 013'],
                ],
                'images' => [[
                    'type' => 'primary',
                    'uri' => 'https://discogs.test/image/bring-the-noise-pump-kin.jpg',
                    'uri150' => 'https://discogs.test/image/bring-the-noise-pump-kin-thumb.jpg',
                ]],
                'tracklist' => [[
                    'position' => '1',
                    'title' => 'Bring The Noise (Pump-Kin Remix)',
                    'duration' => '6:38',
                ]],
            ]);
        }

        if ($url === 'https://discogs.test/releases/1120943') {
            return Http::response([
                'id' => 1120943,
                'master_id' => 2006623,
                'uri' => 'https://www.discogs.com/release/1120943-Public-Enemy-Bring-The-Noise-Remix',
                'title' => 'Bring The Noise Remix',
                'country' => 'UK',
                'released' => '2007',
                'artists' => [
                    ['name' => 'Public Enemy'],
                ],
                'images' => [[
                    'type' => 'primary',
                    'uri' => 'https://discogs.test/image/bring-the-noise-alt.jpg',
                ]],
                'tracklist' => [[
                    'position' => '4',
                    'title' => 'Bring The Noise (Pump-kin Remix)',
                    'duration' => '6:37',
                ]],
            ]);
        }

        if ($url === 'https://ollama.test/v1/audio/metadata-review') {
            $schema = (string) ($request->data()['schemaVersion'] ?? '');
            $aiSchemas[] = $schema;

            return Http::response(match ($schema) {
                'atlas-audio-metadata-discogs-search-v1' => [
                    'queries' => [[
                        'release_title' => 'Bring the Noise Remix [Pump-Kin Remix]',
                        'artist' => 'Public Enemy/Benny Benassi',
                        'reason' => 'Weak production-shaped query with a slash-combined artist.',
                    ]],
                    'model' => 'qwen-test',
                ],
                'atlas-audio-metadata-field-adjudication-v1' => [
                    'verdict' => 'ambiguous',
                    'confidence' => 0.9,
                    'reason' => 'Title change does not match current evidence.',
                    'model' => 'qwen-test',
                    'safe_fields' => [],
                ],
                default => [
                    'verdict' => 'accept',
                    'confidence' => 0.93,
                    'reason' => 'Discogs has the same artist family, Pump-Kin track title, and 6:38 duration.',
                    'model' => 'qwen-test',
                    'safe_fields' => [
                        'title',
                        'artists',
                        'album',
                        'track_number',
                        'release_label',
                        'catalog_number',
                        'release_date',
                        'release_country',
                        'discogs_release_id',
                        'cover_url',
                    ],
                ],
            });
        }

        return Http::response([], 404);
    });

    $user = User::factory()->create();
    $file = bringTheNoiseDiscogsRegressionFile();

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'discogs_release')
        ->assertJsonPath('proposal.proposed_values.title', 'Bring The Noise Remix (Pump-kin Remix)')
        ->assertJsonPath('proposal.proposed_values.artists', ['Benny Benassi', 'Public Enemy'])
        ->assertJsonPath('proposal.proposed_values.album', "Rock'N'Rave")
        ->assertJsonPath('proposal.proposed_values.track_number', '1')
        ->assertJsonPath('proposal.proposed_values.disc_number', '2')
        ->assertJsonPath('proposal.proposed_values.release_label', 'Ultra Records')
        ->assertJsonPath('proposal.proposed_values.catalog_number', 'UL 1695-2')
        ->assertJsonPath('proposal.proposed_values.barcode', '6 17465 16952 6')
        ->assertJsonPath('proposal.proposed_values.release_date', '2008-06-03')
        ->assertJsonPath('proposal.proposed_values.release_country', 'US')
        ->assertJsonPath('proposal.proposed_values.discogs_release_id', '1358093')
        ->assertJsonPath('proposal.proposed_values.cover_url', 'https://discogs.test/image/rock-n-rave.jpg')
        ->assertJsonPath('proposal.evidence.discogs_release_url', 'https://www.discogs.com/release/1358093-Benny-Benassi-RockNRave')
        ->assertJsonPath('proposal.evidence.discogs_master_id', '92414')
        ->assertJsonPath('proposal.evidence.matched_existing_fields', ['album', 'artists', 'track', 'duration'])
        ->assertJsonPath('proposal.evidence.field_review.verdict', 'ambiguous')
        ->assertJsonPath('proposal.evidence.field_review.deterministic_override', 'strong_discogs_release_match');

    expect($discogsSearches)->toContain([
        'type' => 'master',
        'release_title' => 'Bring the Noise Remix',
        'artist' => 'Public Enemy',
        'q' => '',
    ])->and($aiSchemas)->toContain('atlas-audio-metadata-field-adjudication-v1');
});

function bringTheNoiseDiscogsRegressionFile(): File
{
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Bring the Noise Remix [Pump-Kin Remix]',
        'filename' => 'mYLpvmu4iLf0rfPhwDYx6TnBPsbPU1NDYEOODlwI.mp3',
        'path' => 'imports/cc/21/mYLpvmu4iLf0rfPhwDYx6TnBPsbPU1NDYEOODlwI.mp3',
        'size' => 9610468,
    ]);

    $artists = Artist::factory()
        ->count(2)
        ->sequence(
            ['name' => 'Benny Benassi', 'normalized_name' => 'benny benassi'],
            ['name' => 'Benny Benassi/Public Enemy', 'normalized_name' => 'benny benassi public enemy'],
        )
        ->create();

    $album = Album::factory()->create([
        'name' => "Rock N' Rave Disc 2",
        'normalized_name' => "rock n' rave disc 2",
    ]);

    $file->artists()->sync($artists->modelKeys());
    $file->albums()->sync([$album->id]);
    $file->metadata()->create([
        'payload' => [
            'title' => 'Bring the Noise Remix [Pump-Kin Remix]',
            'duration' => 398.18448979591835,
            'track' => '1',
        ],
    ]);

    return $file;
}
