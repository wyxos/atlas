<?php

use App\Models\Album;
use App\Models\Artist;
use App\Models\File;
use App\Models\User;
use App\Services\Audio\AudioFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('discogs release lookup proposes release packaging details when musicbrainz has no match', function () {
    config([
        'services.audio_metadata.discogs_user_token' => 'discogs-token',
        'services.audio_metadata.discogs_api_base_url' => 'https://discogs.test',
        'services.audio_metadata.musicbrainz_api_base_url' => 'https://musicbrainz.test',
    ]);

    $this->mock(AudioFingerprintService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forFile')
            ->once()
            ->andReturn(null);
    });

    Http::fake([
        'https://musicbrainz.test/ws/2/release?*' => Http::response(['releases' => []]),
        'https://discogs.test/database/search*' => Http::response([
            'results' => [
                ['id' => 2969820],
            ],
        ]),
        'https://discogs.test/releases/2969820' => Http::response([
            'id' => 2969820,
            'title' => 'Mysterious Times (UK-Remixes EP)',
            'country' => 'UK',
            'released' => '2011-06-23',
            'artists' => [
                ['name' => 'Sash! Feat Tina Cousins'],
            ],
            'labels' => [
                ['name' => 'Tokapi Recordings', 'catno' => 'TR011'],
            ],
            'identifiers' => [
                ['type' => 'Barcode', 'value' => '8715576130112'],
            ],
            'tracklist' => [
                [
                    'position' => '8',
                    'title' => 'Mysterious Times (Marc Lime & K Bastian Remix)',
                    'duration' => '6:23',
                ],
            ],
        ]),
    ]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Mysterious Times (Marc Lime & K Bastian Remix)',
    ]);
    $artist = Artist::factory()->create([
        'name' => 'Sash! Feat Tina Cousins',
        'normalized_name' => 'sash! feat tina cousins',
    ]);
    $album = Album::factory()->create([
        'name' => 'Mysterious Times (UK-Remixes EP)',
        'normalized_name' => 'mysterious times (uk-remixes ep)',
    ]);
    $file->artists()->sync([$artist->id]);
    $file->albums()->sync([$album->id]);
    $file->metadata()->create([
        'payload' => [
            'duration_seconds' => 383,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'discogs_release')
        ->assertJsonPath('proposal.confidence', 90)
        ->assertJsonPath('proposal.proposed_values.release_label', 'Tokapi Recordings')
        ->assertJsonPath('proposal.proposed_values.catalog_number', 'TR011')
        ->assertJsonPath('proposal.proposed_values.barcode', '8715576130112')
        ->assertJsonPath('proposal.proposed_values.release_date', '2011-06-23')
        ->assertJsonPath('proposal.proposed_values.release_country', 'UK')
        ->assertJsonPath('proposal.proposed_values.track_number', '8')
        ->assertJsonPath('proposal.proposed_values.discogs_release_id', '2969820')
        ->assertJsonPath('proposal.evidence.source', 'discogs_release_search')
        ->assertJsonPath('proposal.evidence.discogs_release_id', '2969820')
        ->assertJsonPath('proposal.evidence.track_position', '8');
});

test('discogs album fallback can propose a cover when artist script differs', function () {
    config([
        'services.audio_metadata.discogs_user_token' => 'discogs-token',
        'services.audio_metadata.discogs_api_base_url' => 'https://discogs.test',
        'services.audio_metadata.musicbrainz_api_base_url' => 'https://musicbrainz.test',
    ]);

    $this->mock(AudioFingerprintService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forFile')
            ->once()
            ->andReturn(null);
    });

    Http::fake([
        'https://musicbrainz.test/ws/2/release?*' => Http::response(['releases' => []]),
        'https://discogs.test/database/search*' => Http::sequence()
            ->push(['results' => []])
            ->push(['results' => []])
            ->push(['results' => [['id' => 17124567]]]),
        'https://discogs.test/releases/17124567' => Http::response([
            'id' => 17124567,
            'title' => 'TVアニメーション GTO オリジナルサウンドトラック = TV Animation GTO Original Soundtrack',
            'country' => 'Japan',
            'released' => '1999',
            'artists' => [
                ['name' => '本間 勇輔*'],
            ],
            'labels' => [
                ['name' => 'Aniplex', 'catno' => 'SVWC-7033'],
            ],
            'identifiers' => [
                ['type' => 'Barcode', 'value' => '4534530703321'],
            ],
            'images' => [[
                'type' => 'primary',
                'uri' => 'https://discogs.test/image/gto-primary.jpg',
                'uri150' => 'https://discogs.test/image/gto-thumb.jpg',
            ]],
            'tracklist' => [
                [
                    'position' => '1',
                    'title' => 'Theme from GTO',
                    'duration' => '3:21',
                ],
            ],
        ]),
    ]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Theme from GTO',
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
            'duration_seconds' => 201,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'discogs_release')
        ->assertJsonPath('proposal.proposed_values.cover_url', 'https://discogs.test/image/gto-primary.jpg')
        ->assertJsonPath('proposal.proposed_values.discogs_release_id', '17124567')
        ->assertJsonPath('proposal.evidence.discogs_release_id', '17124567')
        ->assertJsonPath('proposal.evidence.cover_source', 'discogs_images');
});
