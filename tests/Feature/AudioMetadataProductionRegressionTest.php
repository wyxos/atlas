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

test('production bring the noise row still field reviews release fields after generic fingerprint ai accept', function () {
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
            ->andReturn(new AudioFingerprint('bring-the-noise-fingerprint', 398, '/tmp/bring-the-noise.mp3'));
    });

    $aiSchemas = [];

    Http::fake(function (Request $request) use (&$aiSchemas) {
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
                            'id' => 'bring-noise-give-it-up-mbid',
                            'title' => 'Bring the Noise (remix) / Give It Up',
                            'date' => '2007-09-11',
                            'country' => 'US',
                        ]],
                    ]],
                ]],
            ]);
        }

        if ($url === 'https://musicbrainz.test/ws/2/release/bring-noise-give-it-up-mbid') {
            return Http::response([
                'id' => 'bring-noise-give-it-up-mbid',
                'title' => 'Bring the Noise (remix) / Give It Up',
                'date' => '2007-09-11',
                'country' => 'US',
                'barcode' => '617465158421',
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

        if ($url === 'https://cover.test/release/bring-noise-give-it-up-mbid') {
            return Http::response([], 404);
        }

        if ($url === 'https://ollama.test/v1/audio/metadata-review') {
            $schema = (string) ($request->data()['schemaVersion'] ?? '');
            $aiSchemas[] = $schema;

            return Http::response(match ($schema) {
                'atlas-audio-metadata-field-adjudication-v1' => [
                    'verdict' => 'ambiguous',
                    'confidence' => 0.82,
                    'reason' => 'The fingerprint supports the recording only; the MusicBrainz release package conflicts with the current Rock N Rave album context.',
                    'model' => 'qwen-test',
                    'safe_fields' => ['musicbrainz_recording_id'],
                ],
                default => [
                    'verdict' => 'accept',
                    'confidence' => 0.84,
                    'reason' => 'The fingerprint and duration indicate the same recording.',
                    'model' => 'qwen-test',
                ],
            });
        }

        return Http::response([], 404);
    });

    $user = User::factory()->create();
    $file = productionBringTheNoiseFile();

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'multi_source_review')
        ->assertJsonPath('proposal.proposed_values', [])
        ->assertJsonPath('proposal.field_options.musicbrainz_recording_id.0.value', 'bring-noise-recording-mbid')
        ->assertJsonPath('proposal.field_options.album.0.value', 'Bring the Noise (remix) / Give It Up')
        ->assertJsonPath('proposal.evidence.field_review', null);

    expect($aiSchemas)->toContain('atlas-audio-metadata-review-v1')
        ->and($aiSchemas)->not->toContain('atlas-audio-metadata-field-adjudication-v1');
});

test('production audio sample matrix keeps release fields behind field review after generic fingerprint ai accept', function (array $fixture) {
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

    $recordingId = 'production-recording-'.$fixture['prod_id'];
    $releaseId = 'production-release-'.$fixture['prod_id'];
    $wrongAlbum = 'Conflicting Release Package '.$fixture['prod_id'];

    $this->mock(AudioFingerprintService::class, function (MockInterface $mock) use ($fixture): void {
        $mock->shouldReceive('forFile')
            ->once()
            ->andReturn(new AudioFingerprint('production-fingerprint-'.$fixture['prod_id'], (int) round($fixture['duration']), '/tmp/production-'.$fixture['prod_id'].'.mp3'));
    });

    Http::fake(function (Request $request) use ($fixture, $recordingId, $releaseId, $wrongAlbum) {
        $url = $request->url();

        if (str_starts_with($url, 'https://acoustid.test/v2/lookup')) {
            return Http::response([
                'status' => 'ok',
                'results' => [[
                    'id' => 'acoustid-'.$fixture['prod_id'],
                    'score' => 0.99,
                    'recordings' => [[
                        'id' => $recordingId,
                        'title' => $fixture['title'].' alternate recording title',
                        'duration' => (int) round($fixture['duration'] * 1000),
                        'artists' => [['name' => $fixture['artists'][0].' alternate']],
                        'releases' => [[
                            'id' => $releaseId,
                            'title' => $wrongAlbum,
                            'date' => '1999-01-01',
                            'country' => 'US',
                        ]],
                    ]],
                ]],
            ]);
        }

        if ($url === 'https://musicbrainz.test/ws/2/release/'.$releaseId) {
            return Http::response([
                'id' => $releaseId,
                'title' => $wrongAlbum,
                'date' => '1999-01-01',
                'country' => 'US',
                'barcode' => '000000000000',
                'label-info' => [[
                    'catalog-number' => 'BAD-'.$fixture['prod_id'],
                    'label' => ['name' => 'Wrong Label'],
                ]],
                'media' => [[
                    'position' => 2,
                    'tracks' => [[
                        'number' => '99',
                        'position' => 99,
                        'recording' => ['id' => $recordingId],
                    ]],
                ]],
            ]);
        }

        if ($url === 'https://cover.test/release/'.$releaseId) {
            return Http::response([], 404);
        }

        if ($url === 'https://ollama.test/v1/audio/metadata-review') {
            return Http::response(match ((string) ($request->data()['schemaVersion'] ?? '')) {
                'atlas-audio-metadata-field-adjudication-v1' => [
                    'verdict' => 'ambiguous',
                    'confidence' => 0.8,
                    'reason' => 'The fingerprint supports the recording only; release package fields conflict with the local audio row.',
                    'model' => 'qwen-test',
                    'safe_fields' => ['musicbrainz_recording_id'],
                ],
                default => [
                    'verdict' => 'accept',
                    'confidence' => 0.84,
                    'reason' => 'The fingerprint and duration indicate the same recording.',
                    'model' => 'qwen-test',
                ],
            });
        }

        return Http::response([], 404);
    });

    $user = User::factory()->create();
    $file = productionFixtureFile($fixture);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.proposed_values', [])
        ->assertJsonPath('proposal.field_options.musicbrainz_recording_id.0.value', $recordingId)
        ->assertJsonPath('proposal.field_options.album.0.value', $wrongAlbum)
        ->assertJsonPath('proposal.evidence.field_review', null);
})->with('production audio matrix');

test('discogs master search can provide cover and attribution when release search misses production shape', function () {
    config([
        'services.audio_metadata.discogs_user_token' => 'discogs-token',
        'services.audio_metadata.discogs_api_base_url' => 'https://discogs.test',
        'services.audio_metadata.musicbrainz_api_base_url' => 'https://musicbrainz.test',
        'services.audio_metadata.ai_enabled' => false,
    ]);

    $this->mock(AudioFingerprintService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forFile')->once()->andReturn(null);
    });

    $discogsSearchTypes = [];

    Http::fake(function (Request $request) use (&$discogsSearchTypes) {
        $url = $request->url();

        if (str_starts_with($url, 'https://musicbrainz.test/ws/2/release?')) {
            return Http::response(['releases' => []]);
        }

        if (str_starts_with($url, 'https://discogs.test/database/search')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $discogsSearchTypes[] = (string) ($query['type'] ?? '');

            if (($query['type'] ?? null) === 'master') {
                return Http::response([
                    'results' => [[
                        'id' => 2006623,
                        'type' => 'master',
                        'master_id' => 2006623,
                        'master_url' => 'https://discogs.test/masters/2006623',
                        'title' => 'Public Enemy vs. Benny Benassi - Bring The Noise (Remix)',
                    ]],
                ]);
            }

            return Http::response(['results' => []]);
        }

        if ($url === 'https://discogs.test/masters/2006623') {
            return Http::response([
                'id' => 2006623,
                'main_release' => 3259171,
                'uri' => 'https://www.discogs.com/master/2006623-Public-Enemy-vs-Benny-Benassi-Bring-The-Noise-Remix',
                'title' => 'Bring The Noise (Remix)',
            ]);
        }

        if ($url === 'https://discogs.test/releases/3259171') {
            return Http::response([
                'id' => 3259171,
                'master_id' => 2006623,
                'master_url' => 'https://discogs.test/masters/2006623',
                'uri' => 'https://www.discogs.com/release/3259171-Public-Enemy-vs-Benny-Benassi-Bring-The-Noise-Remix',
                'title' => 'Bring The Noise (Remix)',
                'country' => 'France',
                'released' => '2007',
                'artists' => [
                    ['name' => 'Public Enemy'],
                    ['name' => 'Benny Benassi'],
                ],
                'labels' => [
                    ['name' => 'Sony BMG Music Entertainment', 'catno' => '297750 0247 1'],
                ],
                'identifiers' => [
                    ['type' => 'Barcode', 'value' => '3297750024715'],
                ],
                'images' => [[
                    'type' => 'primary',
                    'uri' => 'https://discogs.test/image/bring-the-noise-primary.jpg',
                    'uri150' => 'https://discogs.test/image/bring-the-noise-thumb.jpg',
                ]],
                'tracklist' => [[
                    'position' => '1',
                    'title' => 'Bring The Noise (Benny Benassi Pump-Kin Edit)',
                    'duration' => '3:37',
                ]],
            ]);
        }

        return Http::response([], 404);
    });

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Bring the Noise Remix [Pump-Kin Remix]',
        'filename' => 'mYLpvmu4iLf0rfPhwDYx6TnBPsbPU1NDYEOODlwI.mp3',
    ]);
    $file->artists()->sync([
        Artist::factory()->create([
            'name' => 'Public Enemy',
            'normalized_name' => 'public enemy',
        ])->id,
        Artist::factory()->create([
            'name' => 'Benny Benassi',
            'normalized_name' => 'benny benassi',
        ])->id,
    ]);
    $file->albums()->sync([
        Album::factory()->create([
            'name' => 'Bring The Noise Remix',
            'normalized_name' => 'bring the noise remix',
        ])->id,
    ]);
    $file->metadata()->create([
        'payload' => [
            'title' => 'Bring the Noise Remix [Pump-Kin Remix]',
            'duration' => 398.18448979591835,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'discogs_release')
        ->assertJsonPath('proposal.proposed_values', [])
        ->assertJsonPath('proposal.field_options.album.0.value', 'Bring The Noise (Remix)')
        ->assertJsonPath('proposal.field_options.release_label.0.value', 'Sony BMG Music Entertainment')
        ->assertJsonPath('proposal.field_options.catalog_number.0.value', '297750 0247 1')
        ->assertJsonPath('proposal.field_options.barcode.0.value', '3297750024715')
        ->assertJsonPath('proposal.field_options.release_date.0.value', '2007')
        ->assertJsonPath('proposal.field_options.release_country.0.value', 'France')
        ->assertJsonPath('proposal.field_options.discogs_release_id.0.value', '3259171')
        ->assertJsonPath('proposal.field_options.cover_url.0.value', 'https://discogs.test/image/bring-the-noise-primary.jpg')
        ->assertJsonPath('proposal.evidence.discogs_release_url', 'https://www.discogs.com/release/3259171-Public-Enemy-vs-Benny-Benassi-Bring-The-Noise-Remix')
        ->assertJsonPath('proposal.evidence.discogs_master_id', '2006623')
        ->assertJsonPath('proposal.evidence.discogs_master_url', 'https://www.discogs.com/master/2006623-Public-Enemy-vs-Benny-Benassi-Bring-The-Noise-Remix');

    expect($discogsSearchTypes)->toContain('release')
        ->and($discogsSearchTypes)->toContain('master');
});

dataset('production audio matrix', [
    'prod 2 3 doors down believer' => [[
        'prod_id' => 2,
        'title' => 'Believer',
        'album' => 'Time Of My Life',
        'artists' => ['3 Doors Down'],
        'duration' => 177.99836734693878,
        'track' => '12',
        'size' => 7133962,
    ]],
    'prod 9 back-on chain' => [[
        'prod_id' => 9,
        'title' => 'Chain',
        'album' => 'Chain',
        'artists' => ['BACK-ON'],
        'duration' => 220.39510204081634,
        'track' => '1',
        'size' => 5292295,
    ]],
    'prod 11 gto theme' => [[
        'prod_id' => 11,
        'title' => 'Theme from GTO',
        'album' => 'GTO TV Animation Original Soundtrack',
        'artists' => ['Yusuke Honma'],
        'duration' => 201.14285714285714,
        'track' => '1',
        'size' => 8049094,
    ]],
]);

function productionBringTheNoiseFile(): File
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

function productionFixtureFile(array $fixture): File
{
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => $fixture['title'],
        'filename' => 'production-'.$fixture['prod_id'].'.mp3',
        'size' => $fixture['size'],
    ]);

    $artists = collect($fixture['artists'])
        ->map(fn (string $artist): int => Artist::factory()->create([
            'name' => $artist,
            'normalized_name' => normalizedProductionFixtureName($artist),
        ])->id);

    $album = Album::factory()->create([
        'name' => $fixture['album'],
        'normalized_name' => normalizedProductionFixtureName($fixture['album']),
    ]);

    $file->artists()->sync($artists->all());
    $file->albums()->sync([$album->id]);
    $file->metadata()->create([
        'payload' => [
            'title' => $fixture['title'],
            'duration' => $fixture['duration'],
            'track' => $fixture['track'],
        ],
    ]);

    return $file;
}

function normalizedProductionFixtureName(string $value): string
{
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($value)));
}
