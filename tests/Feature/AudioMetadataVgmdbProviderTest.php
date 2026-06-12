<?php

use App\Models\Album;
use App\Models\Artist;
use App\Models\File;
use App\Models\User;
use App\Services\Audio\AudioFingerprint;
use App\Services\Audio\AudioFingerprintService;
use App\Services\Audio\AudioMetadataVgmdbProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('vgmdb participates in local metadata lookup by default', function () {
    config([
        'services.audio_metadata.vgmdb_enabled' => true,
        'services.audio_metadata.vgmdb_api_base_url' => 'https://vgmdb.test',
    ]);

    Http::fake([
        'https://vgmdb.test/search/albums*' => Http::response([
            'results' => [
                'albums' => [[
                    'link' => 'album/63806',
                    'catalog' => 'VICL-60728',
                    'release_date' => '2001-04-21',
                ]],
            ],
        ]),
        'https://vgmdb.test/album/63806*' => Http::response([
            'link' => 'album/63806',
            'name' => 'The SoulTaker Original Soundtrack',
            'names' => [
                'en' => 'The SoulTaker Original Soundtrack',
            ],
            'catalog' => 'VICL-60728',
            'release_date' => '2001-04-21',
            'publisher' => [
                'names' => ['en' => 'Victor Entertainment'],
            ],
            'picture_full' => 'https://vgmdb.test/covers/63806-front.jpg',
            'vocals' => [[
                'names' => ['en' => 'JAM Project'],
            ]],
            'discs' => [[
                'name' => 'Disc 1',
                'tracks' => [[
                    'names' => ['en' => 'SOULTAKER'],
                    'track_length' => '4:26',
                ]],
            ]],
        ]),
    ]);

    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'The Soultaker',
        'filename' => 'The Soultaker.mp3',
    ]);

    $candidate = app(AudioMetadataVgmdbProvider::class)->candidate($file, [
        'title' => 'The Soultaker',
        'duration_seconds' => 266,
    ]);

    expect($candidate)->not->toBeNull()
        ->and($candidate['provider'])->toBe('vgmdb_album')
        ->and($candidate['values']['title'])->toBe('SOULTAKER')
        ->and($candidate['values']['album'])->toBe('The SoulTaker Original Soundtrack')
        ->and($candidate['values']['release_label'])->toBe('Victor Entertainment')
        ->and($candidate['values']['catalog_number'])->toBe('VICL-60728');
});

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
        ->assertJsonPath('proposal.provider', 'multi_source_review')
        ->assertJsonPath('proposal.proposed_values', [])
        ->assertJsonPath('proposal.field_options.title.0.value', '刻司ル十二ノ盟約')
        ->assertJsonPath('proposal.field_options.artists.0.value', ['ファンタズム', 'FES', '榊原ゆい'])
        ->assertJsonPath('proposal.field_options.album.0.value', '刻司ル十二ノ盟約')
        ->assertJsonPath('proposal.field_options.release_label.0.value', '5pb. Records')
        ->assertJsonPath('proposal.field_options.catalog_number.0.value', 'MFCZ-1008')
        ->assertJsonPath('proposal.field_options.release_date.0.value', '2011-05-25')
        ->assertJsonPath('proposal.field_options.track_number.0.value', '1')
        ->assertJsonPath('proposal.field_options.disc_number.0.value', '1')
        ->assertJsonPath('proposal.field_options.cover_url.0.value', 'https://vgmdb.test/covers/23849-front.jpg')
        ->assertJsonPath('proposal.evidence.vgmdb_album_id', '23849')
        ->assertJsonPath('proposal.evidence.cover_source', 'vgmdb')
        ->assertJsonMissingPath('proposal.proposed_values.title_aliases')
        ->assertJsonMissingPath('proposal.proposed_values.album_aliases');
});

test('vgmdb lookup stops after a transport failure', function () {
    config([
        'services.audio_metadata.vgmdb_api_base_url' => 'https://vgmdb.test',
        'services.audio_metadata.vgmdb_enabled' => true,
    ]);

    $attempts = 0;

    Http::fake(function () use (&$attempts): never {
        $attempts++;

        throw new ConnectionException('No route to host');
    });

    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Tokitsukasadoru Juuni no Meiyaku',
        'filename' => 'tokitsukasadoru-juuni-no-meiyaku.mp3',
    ]);

    $candidate = app(AudioMetadataVgmdbProvider::class)->candidate($file, [
        'title' => 'Tokitsukasadoru Juuni no Meiyaku',
        'album' => 'Tokitsukasadoru Juuni no Meiyaku',
    ]);

    expect($candidate)->toBeNull();
    expect($attempts)->toBe(1);
});

test('vgmdb can identify game soundtrack albums from source-prefixed album names', function () {
    config([
        'services.audio_metadata.vgmdb_api_base_url' => 'https://vgmdb.test',
        'services.audio_metadata.vgmdb_enabled' => true,
    ]);

    Http::fake([
        'https://vgmdb.test/search/albums*8BitStereo%20-%20Contra%20Force*' => Http::response([
            'results' => ['albums' => []],
        ]),
        'https://vgmdb.test/search/albums*Briefing*' => Http::response([
            'results' => ['albums' => []],
        ]),
        'https://vgmdb.test/search/albums*Contra%20Force*' => Http::response([
            'results' => [
                'albums' => [[
                    'link' => 'album/9001',
                    'catalog' => 'GF-1992',
                    'release_date' => '1992-01-01',
                ]],
            ],
        ]),
        'https://vgmdb.test/album/9001*' => Http::response([
            'link' => 'album/9001',
            'name' => 'Contra Force Original Game Soundtrack',
            'names' => [
                'en' => 'Contra Force Original Game Soundtrack',
            ],
            'catalog' => 'GF-1992',
            'release_date' => '1992-01-01',
            'publisher' => [
                'names' => ['en' => 'Konami'],
            ],
            'picture_full' => 'https://vgmdb.test/covers/contra-force-front.jpg',
            'composers' => [
                ['names' => ['en' => 'Kenichi Matsubara']],
                ['names' => ['en' => 'Yasuhiko Manno']],
            ],
            'discs' => [[
                'name' => 'Disc 1',
                'tracks' => [[
                    'names' => ['en' => 'Briefing'],
                    'track_length' => '1:08',
                ]],
            ]],
        ]),
    ]);

    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => '09 Briefing (Mission Interlude)',
        'filename' => '09 Briefing (Mission Interlude).mp3',
    ]);

    $candidate = app(AudioMetadataVgmdbProvider::class)->candidate($file, [
        'title' => '09 Briefing (Mission Interlude)',
        'artists' => ['Kenichi Matsubara', 'Yasuhiko Manno'],
        'album' => '8BitStereo - Contra Force',
        'duration_seconds' => 68,
    ]);

    expect($candidate)->not->toBeNull()
        ->and($candidate['provider'])->toBe('vgmdb_album')
        ->and($candidate['values']['album'])->toBe('Contra Force Original Game Soundtrack')
        ->and($candidate['values']['title'])->toBe('Briefing')
        ->and($candidate['values']['artists'])->toBe(['Kenichi Matsubara', 'Yasuhiko Manno'])
        ->and($candidate['values']['cover_url'])->toBe('https://vgmdb.test/covers/contra-force-front.jpg')
        ->and($candidate['evidence']['matched_existing_fields'])->toContain('album', 'track', 'duration');
});
