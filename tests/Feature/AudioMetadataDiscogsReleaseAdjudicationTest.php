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

test('ai adjudicates competing discogs releases before proposing release-level metadata', function () {
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

    $aiSchemas = [];
    $adjudicationInput = null;

    Http::fake(function (Request $request) use (&$aiSchemas, &$adjudicationInput) {
        $url = $request->url();

        if (str_starts_with($url, 'https://musicbrainz.test/ws/2/release?')) {
            return Http::response(['releases' => []]);
        }

        if (str_starts_with($url, 'https://discogs.test/database/search')) {
            return Http::response([
                'results' => [
                    ['id' => 13466177, 'type' => 'release'],
                    ['id' => 2588959, 'type' => 'release'],
                ],
            ]);
        }

        if ($url === 'https://discogs.test/releases/13466177') {
            return Http::response(tronLegacyDigitalDiscogsRelease());
        }

        if ($url === 'https://discogs.test/releases/2588959') {
            return Http::response(tronLegacySpecialEditionDiscogsRelease());
        }

        if ($url === 'https://ollama.test/v1/audio/metadata-review') {
            $data = $request->data();
            $schema = (string) ($data['schemaVersion'] ?? '');
            $aiSchemas[] = $schema;

            if ($schema === 'atlas-audio-metadata-discogs-release-adjudication-v1') {
                $adjudicationInput = $data['input'] ?? null;

                return Http::response([
                    'verdict' => 'accept',
                    'confidence' => 0.96,
                    'reason' => 'The current album says CD2, and release 2588959 exposes Castor as disc 2 track 4 while 13466177 is a 29-file digital edition.',
                    'model' => 'qwen-test',
                    'selected_release_id' => '2588959',
                    'selected_track_position' => '2-4',
                    'safe_fields' => [
                        'album',
                        'cover_url',
                        'track_number',
                        'disc_number',
                        'release_label',
                        'catalog_number',
                        'barcode',
                        'release_date',
                        'release_country',
                        'discogs_release_id',
                    ],
                    'rejected_candidates' => [[
                        'release_id' => '13466177',
                        'reason' => 'Digital 29-track release does not match the CD2 local album context.',
                    ]],
                ]);
            }

            return Http::response([
                'verdict' => 'accept',
                'confidence' => 0.94,
                'reason' => 'The AI-selected Discogs release matches the current title, artist, duration, and CD2 context.',
                'model' => 'qwen-test',
                'safe_fields' => [
                    'album',
                    'cover_url',
                    'track_number',
                    'disc_number',
                    'release_label',
                    'catalog_number',
                    'barcode',
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
        'title' => 'Castor',
        'filename' => '04-castor.mp3',
    ]);
    $artist = Artist::factory()->create([
        'name' => 'Daft Punk',
        'normalized_name' => 'daft punk',
    ]);
    $album = Album::factory()->create([
        'name' => 'Tron: Legacy (Cd2)',
        'normalized_name' => 'tron legacy cd2',
    ]);
    $file->artists()->sync([$artist->id]);
    $file->albums()->sync([$album->id]);
    $file->metadata()->create([
        'payload' => [
            'duration_seconds' => 139,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'discogs_release')
        ->assertJsonPath('proposal.proposed_values.discogs_release_id', '2588959')
        ->assertJsonPath('proposal.proposed_values.track_number', '4')
        ->assertJsonPath('proposal.proposed_values.disc_number', '2')
        ->assertJsonPath('proposal.proposed_values.release_label', 'Walt Disney Records')
        ->assertJsonPath('proposal.proposed_values.catalog_number', '50999 9472892 7')
        ->assertJsonPath('proposal.proposed_values.barcode', '5 099994 728927')
        ->assertJsonPath('proposal.proposed_values.release_date', '2010-12-08')
        ->assertJsonPath('proposal.proposed_values.release_country', 'Europe')
        ->assertJsonPath('proposal.evidence.discogs_release_id', '2588959')
        ->assertJsonPath('proposal.evidence.track_position', '2-4')
        ->assertJsonPath('proposal.evidence.release_adjudication.selected_release_id', '2588959');

    expect($aiSchemas)->toContain('atlas-audio-metadata-discogs-release-adjudication-v1')
        ->and(data_get($adjudicationInput, 'candidates.*.release_id'))->toContain('13466177', '2588959');
});

test('ambiguous discogs release adjudication does not fall back to deterministic release packaging', function () {
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

    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_starts_with($url, 'https://musicbrainz.test/ws/2/release?')) {
            return Http::response(['releases' => []]);
        }

        if (str_starts_with($url, 'https://discogs.test/database/search')) {
            return Http::response([
                'results' => [
                    ['id' => 13466177, 'type' => 'release'],
                    ['id' => 2588959, 'type' => 'release'],
                ],
            ]);
        }

        if ($url === 'https://discogs.test/releases/13466177') {
            return Http::response(tronLegacyDigitalDiscogsRelease());
        }

        if ($url === 'https://discogs.test/releases/2588959') {
            return Http::response(tronLegacySpecialEditionDiscogsRelease());
        }

        if ($url === 'https://ollama.test/v1/audio/metadata-review') {
            return Http::response([
                'verdict' => 'ambiguous',
                'confidence' => 0.68,
                'reason' => 'Both releases match title and duration, but the edition evidence is insufficient.',
                'model' => 'qwen-test',
                'selected_release_id' => null,
                'selected_track_position' => null,
                'safe_fields' => [],
                'rejected_candidates' => [],
            ]);
        }

        return Http::response([], 404);
    });

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Castor',
        'filename' => '04-castor.mp3',
    ]);
    $artist = Artist::factory()->create([
        'name' => 'Daft Punk',
        'normalized_name' => 'daft punk',
    ]);
    $album = Album::factory()->create([
        'name' => 'Tron: Legacy (Cd2)',
        'normalized_name' => 'tron legacy cd2',
    ]);
    $file->artists()->sync([$artist->id]);
    $file->albums()->sync([$album->id]);
    $file->metadata()->create([
        'payload' => [
            'duration_seconds' => 139,
        ],
    ]);

    $this->actingAs($user)
        ->postJson("/api/audio/{$file->id}/metadata-runs")
        ->assertAccepted()
        ->assertJsonPath('proposal', null);
});

/**
 * @return array<string, mixed>
 */
function tronLegacyDigitalDiscogsRelease(): array
{
    return [
        'id' => 13466177,
        'title' => 'TRON: Legacy (Original Motion Picture Soundtrack)',
        'country' => 'Europe',
        'released' => '2010-01-01',
        'artists' => [
            ['name' => 'Daft Punk'],
        ],
        'labels' => [
            ['name' => 'Parlophone', 'catno' => 'none'],
        ],
        'formats' => [[
            'name' => 'File',
            'qty' => '29',
            'descriptions' => ['FLAC', 'Album'],
        ]],
        'images' => [[
            'type' => 'secondary',
            'uri' => 'https://discogs.test/image/tron-digital.jpg',
        ]],
        'tracklist' => [
            [
                'position' => '27',
                'title' => 'Castor',
                'duration' => '2:19',
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function tronLegacySpecialEditionDiscogsRelease(): array
{
    return [
        'id' => 2588959,
        'title' => 'TRON: Legacy (Original Motion Picture Soundtrack)',
        'country' => 'Europe',
        'released' => '2010-12-08',
        'artists' => [
            ['name' => 'Daft Punk'],
        ],
        'labels' => [
            ['name' => 'Walt Disney Records', 'catno' => '50999 9472892 7'],
        ],
        'identifiers' => [
            ['type' => 'Barcode', 'value' => '5 099994 728927'],
        ],
        'formats' => [
            [
                'name' => 'CD',
                'qty' => '1',
                'descriptions' => ['Album', 'Enhanced'],
            ],
            [
                'name' => 'CD',
                'qty' => '1',
                'descriptions' => ['Enhanced'],
            ],
            [
                'name' => 'All Media',
                'qty' => '1',
                'descriptions' => ['Special Edition'],
            ],
        ],
        'images' => [[
            'type' => 'primary',
            'uri' => 'https://discogs.test/image/tron-special-edition.jpg',
        ]],
        'tracklist' => [
            [
                'position' => '2-4',
                'title' => 'Castor',
                'duration' => '2:19',
            ],
        ],
    ];
}
