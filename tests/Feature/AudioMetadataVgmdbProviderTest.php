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

test('vgmdb supplements a strong musicbrainz fingerprint release with cover art and catalog details', function () {
    config([
        'services.audio_metadata.acoustid_client_key' => 'acoustid-client',
        'services.audio_metadata.acoustid_api_base_url' => 'https://acoustid.test/v2',
        'services.audio_metadata.musicbrainz_api_base_url' => 'https://musicbrainz.test',
        'services.audio_metadata.cover_art_archive_base_url' => 'https://cover.test',
        'services.audio_metadata.vgmdb_api_base_url' => 'https://vgmdb.test',
        'services.audio_metadata.vgmdb_enabled' => true,
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
                ['name' => '榊原ゆい', 'artist' => ['name' => '榊原ゆい']],
            ],
            'releases' => [[
                'id' => 'tokitsukasadoru-release',
                'title' => '刻司ル十二ノ盟約',
                'status' => 'Official',
                'date' => '2011-05-25',
                'country' => 'JP',
                'artist-credit' => [
                    ['name' => 'ファンタズム', 'artist' => ['name' => 'ファンタズム']],
                ],
            ]],
        ]),
        'https://musicbrainz.test/ws/2/release/tokitsukasadoru-release*' => Http::response([
            'id' => 'tokitsukasadoru-release',
            'title' => '刻司ル十二ノ盟約',
            'date' => '2011-05-25',
            'country' => 'JP',
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
        'https://vgmdb.test/search/albums*' => Http::response([
            'results' => [
                'albums' => [[
                    'link' => 'album/23849',
                    'catalog' => 'MFCZ-1008',
                    'release_date' => '2011-05-25',
                    'titles' => [
                        'ja' => '刻司ル十二ノ盟約',
                        'ja-latn' => 'Tokitsukasadoru Juuni no Meiyaku',
                        'en' => 'Toki Tsukasadoru Juuni no Meiyaku',
                    ],
                ]],
            ],
        ]),
        'https://vgmdb.test/album/23849*' => Http::response([
            'link' => 'album/23849',
            'name' => '刻司ル十二ノ盟約',
            'names' => [
                'ja' => '刻司ル十二ノ盟約',
                'ja-latn' => 'Tokitsukasadoru Juuni no Meiyaku',
                'en' => 'Toki Tsukasadoru Juuni no Meiyaku',
            ],
            'catalog' => 'MFCZ-1008',
            'release_date' => '2011-05-25',
            'publisher' => [
                'names' => ['en' => '5pb. Records'],
            ],
            'picture_full' => 'https://vgmdb.test/covers/23849-front.jpg',
            'covers' => [[
                'name' => 'Front',
                'full' => 'https://vgmdb.test/covers/23849-front.jpg',
                'medium' => 'https://vgmdb.test/covers/23849-medium.jpg',
                'thumb' => 'https://vgmdb.test/covers/23849-thumb.jpg',
            ]],
            'vocals' => [[
                'names' => [
                    'ja' => 'ファンタズム',
                    'ja-latn' => 'Phantasm',
                ],
            ]],
            'discs' => [[
                'name' => 'Disc 1',
                'disc_length' => '20:00',
                'tracks' => [
                    [
                        'names' => [
                            'ja' => '刻司ル十二ノ盟約',
                            'ja-latn' => 'Tokitsukasadoru Juuni no Meiyaku',
                        ],
                        'track_length' => '5:03',
                    ],
                    [
                        'names' => ['ja' => '麗しきセデュース'],
                        'track_length' => '4:52',
                    ],
                ],
            ]],
        ]),
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
        ->assertJsonPath('proposal.provider', 'acoustid_musicbrainz_vgmdb')
        ->assertJsonPath('proposal.proposed_values.title', '刻司ル十二ノ盟約')
        ->assertJsonPath('proposal.proposed_values.title_aliases', [
            'Tokitsukasadoru Juuni no Meiyaku',
        ])
        ->assertJsonPath('proposal.proposed_values.artists', ['ファンタズム', 'FES', '榊原ゆい'])
        ->assertJsonPath('proposal.proposed_values.album', '刻司ル十二ノ盟約')
        ->assertJsonPath('proposal.proposed_values.album_aliases', [
            'Tokitsukasadoru Juuni no Meiyaku',
            'Toki Tsukasadoru Juuni no Meiyaku',
        ])
        ->assertJsonPath('proposal.proposed_values.release_label', '5pb. Records')
        ->assertJsonPath('proposal.proposed_values.catalog_number', 'MFCZ-1008')
        ->assertJsonPath('proposal.proposed_values.release_date', '2011-05-25')
        ->assertJsonPath('proposal.proposed_values.track_number', '1')
        ->assertJsonPath('proposal.proposed_values.disc_number', '1')
        ->assertJsonPath('proposal.proposed_values.cover_url', 'https://vgmdb.test/covers/23849-front.jpg')
        ->assertJsonPath('proposal.evidence.vgmdb_album_id', '23849')
        ->assertJsonPath('proposal.evidence.cover_source', 'vgmdb');
});
