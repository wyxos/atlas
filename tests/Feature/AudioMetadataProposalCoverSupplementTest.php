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

test('fingerprint proposal keeps musicbrainz release search cover when fingerprint has no release', function () {
    config([
        'services.audio_metadata.acoustid_client_key' => 'acoustid-client',
        'services.audio_metadata.acoustid_api_base_url' => 'https://acoustid.test/v2',
        'services.audio_metadata.musicbrainz_api_base_url' => 'https://musicbrainz.test',
        'services.audio_metadata.cover_art_archive_base_url' => 'https://cover.test',
    ]);

    $this->mock(AudioFingerprintService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forFile')
            ->once()
            ->andReturn(new AudioFingerprint('chain-fingerprint', 220, '/tmp/chain.mp3'));
    });

    Http::fake([
        'https://acoustid.test/v2/lookup*' => Http::response([
            'status' => 'ok',
            'results' => [[
                'id' => 'acoustid-chain',
                'score' => 1.0,
                'recordings' => [[
                    'id' => 'chain-recording-mbid',
                    'title' => 'Chain',
                    'duration' => 219000,
                    'artists' => [
                        ['name' => 'BACK-ON'],
                    ],
                ]],
            ]],
        ]),
        'https://musicbrainz.test/ws/2/release/chain-release*' => Http::response([
            'id' => 'chain-release',
            'title' => 'Chain',
            'date' => '2006-06-07',
            'country' => 'JP',
        ]),
        'https://musicbrainz.test/ws/2/release?*' => Http::response([
            'releases' => [[
                'id' => 'chain-release',
                'score' => 100,
                'title' => 'Chain',
                'artist-credit' => [[
                    'name' => 'BACK-ON',
                    'artist' => ['name' => 'BACK-ON'],
                ]],
            ]],
        ]),
        'https://cover.test/release/chain-release' => Http::response([
            'images' => [[
                'front' => true,
                'image' => 'https://cover.test/release/chain-release/front.jpg',
                'thumbnails' => [
                    'large' => 'https://cover.test/release/chain-release/front-500.jpg',
                ],
            ]],
        ]),
    ]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Chain',
        'filename' => 'chain.mp3',
    ]);
    $artist = Artist::factory()->create([
        'name' => 'BACK-ON',
        'normalized_name' => 'back-on',
    ]);
    $album = Album::factory()->create([
        'name' => 'Chain',
        'normalized_name' => 'chain',
    ]);
    $file->artists()->sync([$artist->id]);
    $file->albums()->sync([$album->id]);
    $file->metadata()->create([
        'payload' => [
            'duration' => 220.1,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'acoustid_musicbrainz')
        ->assertJsonPath('proposal.proposed_values.cover_url', 'https://cover.test/release/chain-release/front-500.jpg')
        ->assertJsonPath('proposal.evidence.cover_source', 'cover_art_archive')
        ->assertJsonPath('proposal.evidence.musicbrainz_release_id', 'chain-release');

    expect($response->json('proposal.proposed_values'))->not->toHaveKey('musicbrainz_release_id');
});
