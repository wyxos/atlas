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

test('fingerprint metadata follows up musicbrainz recording releases when acoustid omits release data', function () {
    config([
        'services.audio_metadata.acoustid_client_key' => 'acoustid-client',
        'services.audio_metadata.acoustid_api_base_url' => 'https://acoustid.test/v2',
        'services.audio_metadata.musicbrainz_api_base_url' => 'https://musicbrainz.test',
        'services.audio_metadata.cover_art_archive_base_url' => 'https://cover.test',
    ]);

    $this->mock(AudioFingerprintService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forFile')
            ->once()
            ->andReturn(new AudioFingerprint('tokitsukasadoru-fingerprint', 304, '/tmp/tokitsukasadoru.mp3'));
    });

    Http::fake([
        'https://acoustid.test/v2/lookup*' => Http::response([
            'status' => 'ok',
            'results' => [[
                'id' => 'acoustid-tokitsukasadoru',
                'score' => 1.0,
                'recordings' => [[
                    'id' => 'tokitsukasadoru-recording',
                    'title' => '刻司ル十二ノ盟約',
                    'duration' => 303000,
                    'artists' => [
                        ['name' => 'ファンタズム'],
                        ['name' => 'FES'],
                        ['name' => '榊原ゆい'],
                    ],
                ]],
            ]],
        ]),
        'https://musicbrainz.test/ws/2/recording/tokitsukasadoru-recording*' => Http::response([
            'id' => 'tokitsukasadoru-recording',
            'title' => '刻司ル十二ノ盟約',
            'length' => 303000,
            'artist-credit' => [
                ['name' => 'ファンタズム', 'artist' => ['name' => 'ファンタズム']],
                ['name' => 'FES', 'artist' => ['name' => 'FES']],
                [
                    'name' => '榊原ゆい',
                    'artist' => [
                        'name' => '榊原ゆい',
                        'sort-name' => 'Sakakibara, Yui',
                    ],
                ],
            ],
            'releases' => [
                [
                    'id' => 'best-compilation',
                    'title' => 'PHANTASM THE BEST',
                    'status' => 'Official',
                    'date' => '2014-12-24',
                    'country' => 'JP',
                    'barcode' => '4582325373718',
                    'artist-credit' => [
                        ['name' => 'ファンタズム', 'artist' => ['name' => 'ファンタズム']],
                    ],
                ],
                [
                    'id' => 'tokitsukasadoru-release',
                    'title' => '刻司ル十二ノ盟約',
                    'status' => 'Official',
                    'date' => '2011-05-25',
                    'country' => 'JP',
                    'barcode' => '4562207978064',
                    'artist-credit' => [
                        ['name' => 'ファンタズム', 'artist' => ['name' => 'ファンタズム']],
                    ],
                ],
                [
                    'id' => 'tokitsukasadoru-romaji-pseudo',
                    'title' => 'Toki Tsukasadoru Juuni no Meiyaku',
                    'status' => 'Pseudo-Release',
                    'artist-credit' => [
                        ['name' => 'ファンタズム', 'artist' => ['name' => 'ファンタズム']],
                    ],
                ],
            ],
        ]),
        'https://musicbrainz.test/ws/2/release/tokitsukasadoru-release*' => Http::response([
            'id' => 'tokitsukasadoru-release',
            'title' => '刻司ル十二ノ盟約',
            'date' => '2011-05-25',
            'country' => 'JP',
            'barcode' => '4562207978064',
            'label-info' => [[
                'catalog-number' => 'MFCZ-1008',
                'label' => ['name' => '5pb. Records'],
            ]],
            'media' => [[
                'position' => 1,
                'tracks' => [[
                    'number' => '1',
                    'position' => 1,
                    'recording' => ['id' => 'tokitsukasadoru-recording'],
                ]],
            ]],
        ]),
        'https://cover.test/release/tokitsukasadoru-release' => Http::response([], 404),
    ]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Tokitsukasadoru Juuni no Meiyaku',
        'filename' => 'tokitsukasadoru-juuni-no-meiyaku.mp3',
    ]);
    $artist = Artist::factory()->create([
        'name' => 'Sakakibara Yui',
        'normalized_name' => 'sakakibara yui',
    ]);
    $album = Album::factory()->create([
        'name' => 'Tokitsukasadoru Juuni no Meiyaku',
        'normalized_name' => 'tokitsukasadoru juuni no meiyaku',
    ]);
    $file->artists()->sync([$artist->id]);
    $file->albums()->sync([$album->id]);
    $file->metadata()->create([
        'payload' => [
            'title' => 'Tokitsukasadoru Juuni no Meiyaku',
            'artist' => 'Sakakibara Yui',
            'album' => 'Tokitsukasadoru Juuni no Meiyaku',
            'duration' => 303.5,
            'track' => '1',
            'date' => '2011',
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'acoustid_musicbrainz')
        ->assertJsonPath('proposal.proposed_values.title', '刻司ル十二ノ盟約')
        ->assertJsonPath('proposal.proposed_values.artists', ['ファンタズム', 'FES', '榊原ゆい'])
        ->assertJsonPath('proposal.proposed_values.album', '刻司ル十二ノ盟約')
        ->assertJsonPath('proposal.proposed_values.track_number', '1')
        ->assertJsonPath('proposal.proposed_values.release_label', '5pb. Records')
        ->assertJsonPath('proposal.proposed_values.catalog_number', 'MFCZ-1008')
        ->assertJsonPath('proposal.proposed_values.barcode', '4562207978064')
        ->assertJsonPath('proposal.proposed_values.release_date', '2011-05-25')
        ->assertJsonPath('proposal.proposed_values.release_country', 'JP')
        ->assertJsonPath('proposal.proposed_values.musicbrainz_recording_id', 'tokitsukasadoru-recording')
        ->assertJsonPath('proposal.proposed_values.musicbrainz_release_id', 'tokitsukasadoru-release')
        ->assertJsonPath('proposal.evidence.identity_support', 'strong_fingerprint_release')
        ->assertJsonPath('proposal.evidence.musicbrainz_release_id', 'tokitsukasadoru-release')
        ->assertJsonMissingPath('proposal.proposed_values.title_aliases')
        ->assertJsonMissingPath('proposal.proposed_values.artist_aliases')
        ->assertJsonMissingPath('proposal.proposed_values.artist_alias_map')
        ->assertJsonMissingPath('proposal.proposed_values.album_aliases')
        ->assertJsonMissingPath('proposal.proposed_values.cover_url');
});

test('fingerprint metadata prefers a current album release over a same-title single release', function () {
    config([
        'services.audio_metadata.acoustid_client_key' => 'acoustid-client',
        'services.audio_metadata.acoustid_api_base_url' => 'https://acoustid.test/v2',
        'services.audio_metadata.musicbrainz_api_base_url' => 'https://musicbrainz.test',
        'services.audio_metadata.cover_art_archive_base_url' => 'https://cover.test',
    ]);

    $this->mock(AudioFingerprintService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forFile')
            ->once()
            ->andReturn(new AudioFingerprint('amaranthe-leave-everything-behind', 199, '/tmp/leave-everything-behind.mp3'));
    });

    Http::fake([
        'https://acoustid.test/v2/lookup*' => Http::response([
            'status' => 'ok',
            'results' => [[
                'id' => 'acoustid-amaranthe-leave',
                'score' => 0.998,
                'recordings' => [[
                    'id' => 'amaranthe-leave-recording',
                    'title' => 'Leave Everything Behind',
                    'duration' => 198000,
                    'artists' => [
                        ['name' => 'Amaranthe'],
                    ],
                ]],
            ]],
        ]),
        'https://musicbrainz.test/ws/2/recording/amaranthe-leave-recording*' => Http::response([
            'id' => 'amaranthe-leave-recording',
            'title' => 'Leave Everything Behind',
            'length' => 198000,
            'artist-credit' => [
                ['name' => 'Amaranthe', 'artist' => ['name' => 'Amaranthe']],
            ],
            'releases' => [
                [
                    'id' => 'leave-everything-behind-single-release',
                    'title' => 'Leave Everything Behind',
                    'status' => 'Official',
                    'date' => '2009-02-18',
                    'country' => 'SE',
                    'barcode' => '7898237387246',
                    'artist-credit' => [
                        ['name' => 'Amaranthe', 'artist' => ['name' => 'Amaranthe']],
                    ],
                ],
                [
                    'id' => 'amaranthe-album-release',
                    'title' => 'Amaranthe',
                    'status' => 'Official',
                    'date' => '2011-04-13',
                    'country' => 'SE',
                    'artist-credit' => [
                        ['name' => 'Amaranthe', 'artist' => ['name' => 'Amaranthe']],
                    ],
                ],
            ],
        ]),
        'https://musicbrainz.test/ws/2/release/leave-everything-behind-single-release*' => Http::response([
            'id' => 'leave-everything-behind-single-release',
            'title' => 'Leave Everything Behind',
            'date' => '2009-02-18',
            'country' => 'SE',
            'barcode' => '7898237387246',
            'label-info' => [[
                'catalog-number' => 'HEL 0724',
                'label' => ['name' => 'Spinefarm Records'],
            ]],
            'media' => [[
                'position' => 1,
                'tracks' => [[
                    'number' => '2',
                    'position' => 2,
                    'recording' => ['id' => 'amaranthe-leave-recording'],
                ]],
            ]],
        ]),
        'https://musicbrainz.test/ws/2/release/amaranthe-album-release*' => Http::response([
            'id' => 'amaranthe-album-release',
            'title' => 'Amaranthe',
            'date' => '2011-04-13',
            'country' => 'SE',
            'media' => [[
                'position' => 1,
                'tracks' => [[
                    'number' => '3',
                    'position' => 3,
                    'recording' => ['id' => 'amaranthe-leave-recording'],
                ]],
            ]],
        ]),
        'https://cover.test/release/amaranthe-album-release' => Http::response([], 404),
        'https://cover.test/release/leave-everything-behind-single-release' => Http::response([], 404),
    ]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Leave Everything Behind',
        'filename' => '03-leave-everything-behind.mp3',
    ]);
    $artist = Artist::factory()->create([
        'name' => 'Amaranthe',
        'normalized_name' => 'amaranthe',
    ]);
    $album = Album::factory()->create([
        'name' => 'Amaranthe',
        'normalized_name' => 'amaranthe',
    ]);
    $file->artists()->sync([$artist->id]);
    $file->albums()->sync([$album->id]);
    $file->metadata()->create([
        'payload' => [
            'title' => 'Leave Everything Behind',
            'artist' => 'Amaranthe',
            'album' => 'Amaranthe',
            'duration' => 199,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.proposed_values.album', 'Amaranthe')
        ->assertJsonPath('proposal.proposed_values.musicbrainz_release_id', 'amaranthe-album-release')
        ->assertJsonPath('proposal.proposed_values.track_number', '3')
        ->assertJsonMissingPath('proposal.changes.album');
});
