<?php

use App\Models\Album;
use App\Models\Artist;
use App\Models\File;
use App\Models\User;
use App\Services\Audio\AudioFingerprint;
use App\Services\Audio\AudioFingerprintService;
use App\Services\Audio\AudioMetadataProposalGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('ambiguous ai field review is not overridden by album typo matching', function () {
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

    $this->mock(AudioFingerprintService::class, fn (MockInterface $mock) => $mock
        ->shouldReceive('forFile')
        ->once()
        ->andReturn(null));

    fakeChristopherLawrenceDiscogsResponses(fieldReviewResponse: [
        'verdict' => 'ambiguous',
        'confidence' => 0.72,
        'reason' => 'The album title differs from the current value.',
        'model' => 'qwen-test',
        'safe_fields' => [],
    ]);

    $user = User::factory()->create();
    $file = christopherLawrenceDiscogsTypoFile();

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'local')
        ->assertJsonPath('proposal.proposed_values.track_number', '2')
        ->assertJsonPath('proposal.proposed_values.release_label', 'System Recordings')
        ->assertJsonPath('proposal.proposed_values.release_date', '2008');
});

test('ai field review accepts exact track evidence when it judges the current album as a typo', function () {
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

    $this->mock(AudioFingerprintService::class, fn (MockInterface $mock) => $mock
        ->shouldReceive('forFile')
        ->once()
        ->andReturn(null));

    $fieldReviewPrompt = null;
    fakeChristopherLawrenceDiscogsResponses(
        fieldReviewResponse: [
            'verdict' => 'accept',
            'confidence' => 0.93,
            'reason' => 'Discogs release has the same artist, exact track, and duration; current album appears misspelled.',
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
        fieldReviewPrompt: $fieldReviewPrompt,
    );

    $user = User::factory()->create();
    $file = christopherLawrenceDiscogsTypoFile();

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'discogs_release')
        ->assertJsonPath('proposal.proposed_values.title', 'Saboteur (Dub Mix)')
        ->assertJsonPath('proposal.proposed_values.artists', ['Christopher Lawrence'])
        ->assertJsonPath('proposal.proposed_values.album', 'All Or Nothing')
        ->assertJsonPath('proposal.proposed_values.track_number', '15')
        ->assertJsonPath('proposal.proposed_values.release_label', 'Pharmacy Music')
        ->assertJsonPath('proposal.proposed_values.catalog_number', 'PHARMACY1001')
        ->assertJsonPath('proposal.proposed_values.release_date', '2010-09-28')
        ->assertJsonPath('proposal.proposed_values.release_country', 'Australia')
        ->assertJsonPath('proposal.proposed_values.discogs_release_id', '2568225')
        ->assertJsonPath('proposal.proposed_values.cover_url', 'https://discogs.test/image/all-or-nothing.jpg')
        ->assertJsonPath('proposal.evidence.matched_existing_fields', ['artists', 'track', 'duration'])
        ->assertJsonPath('proposal.evidence.duration_delta_seconds', 0)
        ->assertJsonPath('proposal.evidence.field_review.verdict', 'accept')
        ->assertJsonPath('proposal.evidence.field_review.reason', 'Discogs release has the same artist, exact track, and duration; current album appears misspelled.');

    expect($fieldReviewPrompt)
        ->toContain('Do not compare the track title to the album title.')
        ->toContain('safe_fields must be a subset of candidate.values keys.')
        ->toContain('treat the release as release-level evidence');
});

test('strong discogs release evidence wins over musicbrainz recording id only proposal', function () {
    config([
        'services.audio_metadata.acoustid_client_key' => 'acoustid-client',
        'services.audio_metadata.acoustid_api_base_url' => 'https://acoustid.test/v2',
        'services.audio_metadata.cover_art_archive_base_url' => 'https://cover.test',
        'services.audio_metadata.discogs_user_token' => 'discogs-token',
        'services.audio_metadata.discogs_api_base_url' => 'https://discogs.test',
        'services.audio_metadata.musicbrainz_api_base_url' => 'https://musicbrainz.test',
        'services.audio_metadata.vgmdb_enabled' => false,
        'services.audio_metadata.ai_enabled' => true,
        'services.audio_metadata.ai_driver' => 'gateway',
        'services.audio_metadata.ai_base_url' => 'https://ollama.test',
        'services.audio_metadata.ai_token' => 'ai-token',
        'services.audio_metadata.ai_model' => 'qwen-test',
    ]);

    $this->mock(AudioFingerprintService::class, fn (MockInterface $mock) => $mock
        ->shouldReceive('forFile')
        ->once()
        ->andReturn(new AudioFingerprint('saboteur-fingerprint', 474, '/tmp/saboteur.mp3')));

    $fieldReviewedProviders = [];
    Http::fake(function (Request $request) use (&$fieldReviewedProviders) {
        $url = $request->url();

        if (str_starts_with($url, 'https://acoustid.test/v2/lookup')) {
            return Http::response([
                'status' => 'ok',
                'results' => [[
                    'id' => 'acoustid-saboteur',
                    'score' => 0.997,
                    'recordings' => [[
                        'id' => 'saboteur-recording-mbid',
                        'title' => 'Saboteur (Dub Mix)',
                        'duration' => 474000,
                        'artists' => [
                            ['name' => 'Christopher Lawrence'],
                        ],
                        'releases' => [[
                            'id' => 'all-or-nothing-remastered-mbid',
                            'title' => 'All Or Nothing (Remastered)',
                            'date' => '2010-09-28',
                            'country' => 'AU',
                        ]],
                    ]],
                ]],
            ]);
        }

        if ($url === 'https://musicbrainz.test/ws/2/release/all-or-nothing-remastered-mbid') {
            return Http::response([
                'id' => 'all-or-nothing-remastered-mbid',
                'title' => 'All Or Nothing (Remastered)',
                'date' => '2010-09-28',
                'country' => 'AU',
                'label-info' => [[
                    'catalog-number' => 'MB-REM',
                    'label' => ['name' => 'MusicBrainz Label'],
                ]],
                'media' => [[
                    'position' => 1,
                    'tracks' => [[
                        'number' => '15',
                        'position' => 15,
                        'recording' => ['id' => 'saboteur-recording-mbid'],
                    ]],
                ]],
            ]);
        }

        if ($url === 'https://cover.test/release/all-or-nothing-remastered-mbid') {
            return Http::response([], 404);
        }

        if (str_starts_with($url, 'https://musicbrainz.test/ws/2/release?')) {
            return Http::response(['releases' => []]);
        }

        if (str_starts_with($url, 'https://discogs.test/database/search')) {
            return Http::response([
                'results' => [
                    ['id' => 2568225, 'type' => 'release'],
                ],
            ]);
        }

        if ($url === 'https://discogs.test/releases/2568225') {
            return Http::response([
                'id' => 2568225,
                'master_id' => 39320,
                'uri' => 'https://www.discogs.com/release/2568225-Christopher-Lawrence-All-Or-Nothing',
                'title' => 'All Or Nothing',
                'country' => 'Australia',
                'released' => '2010-09-28',
                'artists' => [
                    ['name' => 'Christopher Lawrence'],
                ],
                'labels' => [
                    ['name' => 'Pharmacy Music', 'catno' => 'PHARMACY1001'],
                ],
                'images' => [[
                    'type' => 'primary',
                    'uri' => 'https://discogs.test/image/all-or-nothing.jpg',
                ]],
                'tracklist' => [[
                    'position' => '15',
                    'title' => 'Saboteur (Dub Mix)',
                    'duration' => '7:54',
                ]],
            ]);
        }

        if ($url === 'https://ollama.test/v1/audio/metadata-review') {
            $data = $request->data();
            $schema = (string) ($data['schemaVersion'] ?? '');
            $provider = (string) data_get($data, 'input.candidate.provider');
            if ($schema === 'atlas-audio-metadata-field-adjudication-v1') {
                $fieldReviewedProviders[] = $provider;
            }

            return Http::response(match ($provider) {
                'discogs_release' => [
                    'verdict' => 'accept',
                    'confidence' => 0.93,
                    'reason' => 'Discogs release has matching artist, track, and duration; current album is a typo.',
                    'model' => 'qwen-test',
                    'safe_fields' => [
                        'album',
                        'cover_url',
                        'track_number',
                        'release_label',
                        'catalog_number',
                        'release_date',
                        'release_country',
                        'discogs_release_id',
                    ],
                ],
                default => [
                    'verdict' => 'ambiguous',
                    'confidence' => 0.96,
                    'reason' => 'The fingerprint supports the recording only; the MusicBrainz release package is not safe.',
                    'model' => 'qwen-test',
                    'safe_fields' => ['musicbrainz_recording_id'],
                ],
            });
        }

        return Http::response([], 404);
    });

    $user = User::factory()->create();
    $file = christopherLawrenceDiscogsTypoFile();

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'discogs_release')
        ->assertJsonPath('proposal.proposed_values.album', 'All Or Nothing')
        ->assertJsonPath('proposal.proposed_values.track_number', '15')
        ->assertJsonPath('proposal.proposed_values.release_label', 'Pharmacy Music')
        ->assertJsonPath('proposal.proposed_values.catalog_number', 'PHARMACY1001')
        ->assertJsonPath('proposal.proposed_values.release_date', '2010-09-28')
        ->assertJsonPath('proposal.proposed_values.release_country', 'Australia')
        ->assertJsonPath('proposal.proposed_values.discogs_release_id', '2568225')
        ->assertJsonPath('proposal.proposed_values.cover_url', 'https://discogs.test/image/all-or-nothing.jpg')
        ->assertJsonPath('proposal.evidence.discogs_release_url', 'https://www.discogs.com/release/2568225-Christopher-Lawrence-All-Or-Nothing')
        ->assertJsonPath('proposal.evidence.matched_existing_fields', ['artists', 'track', 'duration'])
        ->assertJsonPath('proposal.evidence.duration_delta_seconds', 0)
        ->assertJsonPath('proposal.evidence.field_review.verdict', 'accept');

    expect($fieldReviewedProviders)->toBe(['discogs_release']);
});

test('weak mixed discogs supplement does not outrank exact discogs release evidence', function () {
    $generator = app(AudioMetadataProposalGenerator::class);
    $priority = new ReflectionMethod($generator, 'candidatePriority');

    $weakMixedPriority = $priority->invoke($generator, [
        'provider' => 'acoustid_musicbrainz_ai_discogs',
        'confidence' => 96,
        'values' => [
            'album' => 'All Or Nothing (Remastered)',
            'title' => 'Saboteur (Dub Remix - Bonus Track)',
            'discogs_release_id' => '2568225',
        ],
        'evidence' => [
            'discogs_release_id' => 2568225,
            'discogs_release_url' => 'https://www.discogs.com/release/2568225-Christopher-Lawrence-All-Or-Nothing',
            'matched_existing_fields' => ['duration', 'artists'],
            'duration_delta_seconds' => 0,
        ],
    ]);
    $strongDiscogsPriority = $priority->invoke($generator, [
        'provider' => 'discogs_release',
        'confidence' => 77,
        'values' => ['discogs_release_id' => '2568225'],
        'evidence' => [
            'track_position' => '15',
            'matched_existing_fields' => ['artists', 'track', 'duration'],
            'duration_delta_seconds' => 0,
        ],
    ]);

    expect($strongDiscogsPriority)->toBeGreaterThan($weakMixedPriority);
});

/**
 * @param  array<string, mixed>  $fieldReviewResponse
 */
function fakeChristopherLawrenceDiscogsResponses(array $fieldReviewResponse, ?string &$fieldReviewPrompt = null): void
{
    Http::fake(function (Request $request) use ($fieldReviewResponse, &$fieldReviewPrompt) {
        $url = $request->url();

        if (str_starts_with($url, 'https://musicbrainz.test/ws/2/release?')) {
            return Http::response(['releases' => []]);
        }

        if (str_starts_with($url, 'https://discogs.test/database/search')) {
            return Http::response([
                'results' => [
                    ['id' => 2568225, 'type' => 'release'],
                ],
            ]);
        }

        if ($url === 'https://discogs.test/releases/2568225') {
            return Http::response([
                'id' => 2568225,
                'master_id' => 39320,
                'uri' => 'https://www.discogs.com/release/2568225-Christopher-Lawrence-All-Or-Nothing',
                'title' => 'All Or Nothing',
                'country' => 'Australia',
                'released' => '2010-09-28',
                'artists' => [
                    ['name' => 'Christopher Lawrence'],
                ],
                'labels' => [
                    ['name' => 'Pharmacy Music', 'catno' => 'PHARMACY1001'],
                ],
                'images' => [[
                    'type' => 'primary',
                    'uri' => 'https://discogs.test/image/all-or-nothing.jpg',
                ]],
                'tracklist' => [[
                    'position' => '15',
                    'title' => 'Saboteur (Dub Mix)',
                    'duration' => '7:54',
                ]],
            ]);
        }

        if ($url === 'https://ollama.test/v1/audio/metadata-review') {
            $fieldReviewPrompt = (string) ($request->data()['prompt'] ?? '');

            return Http::response($fieldReviewResponse);
        }

        return Http::response([], 404);
    });
}

function christopherLawrenceDiscogsTypoFile(): File
{
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Saboteur (Dub Mix)',
        'filename' => 'saboteur-dub-mix.mp3',
    ]);
    $artist = Artist::factory()->create([
        'name' => 'Christopher Lawrence',
        'normalized_name' => 'christopher lawrence',
    ]);
    $album = Album::factory()->create([
        'name' => 'All Ar Nothing',
        'normalized_name' => 'all ar nothing',
    ]);
    $file->artists()->sync([$artist->id]);
    $file->albums()->sync([$album->id]);
    $file->metadata()->create([
        'payload' => [
            'title' => 'Saboteur (Dub Mix)',
            'artist' => 'Christopher Lawrence',
            'album' => 'All Ar Nothing',
            'duration' => 474,
            'track' => '2',
            'label' => 'System Recordings',
            'year' => '2008',
        ],
    ]);

    return $file;
}
