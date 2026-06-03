<?php

use App\Models\Album;
use App\Models\Artist;
use App\Models\File;
use App\Models\User;
use App\Services\Audio\AudioFingerprint;
use App\Services\Audio\AudioFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('local ai can bridge fingerprint identity to discogs original language aliases', function () {
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
        ->assertJsonPath('proposal.proposed_values.title_aliases', ['Bike Investigation'])
        ->assertJsonPath('proposal.proposed_values.artists', ['本間勇輔'])
        ->assertJsonPath('proposal.proposed_values.artist_aliases', ['Yusuke Honma', 'Yusuke Homma'])
        ->assertJsonPath('proposal.proposed_values.album', 'TVアニメーション GTO オリジナルサウンドトラック')
        ->assertJsonPath('proposal.proposed_values.album_aliases', [
            'TV Animation GTO Original Soundtrack',
            'GTO TV Animation Original Soundtrack',
        ])
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
        ->assertJsonPath('proposal.evidence.discogs_release_id', '17124567');
});
