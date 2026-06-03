<?php

use App\Models\Album;
use App\Models\AudioMetadataProposal;
use App\Models\AudioMetadataRun;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('metadata proposal preserves embedded title as alias when applying canonical title', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => null,
        'filename' => 'custom-import-title.mp3',
    ]);
    $file->metadata()->create([
        'payload' => [
            'title' => 'Custom Import Title',
        ],
    ]);
    $run = AudioMetadataRun::query()->create([
        'user_id' => $user->id,
        'scope' => 'single',
        'source_filter' => 'local',
        'status' => 'completed',
        'total_files' => 1,
        'processed_files' => 1,
        'proposal_count' => 1,
        'options' => ['file_id' => $file->id],
    ]);
    $proposal = AudioMetadataProposal::query()->create([
        'audio_metadata_run_id' => $run->id,
        'file_id' => $file->id,
        'provider' => 'discogs_release',
        'status' => 'pending',
        'confidence' => 80,
        'current_values' => ['title' => 'Custom Import Title'],
        'proposed_values' => ['title' => 'Canonical Source Title'],
        'changes' => [
            'title' => [
                'current' => 'Custom Import Title',
                'proposed' => 'Canonical Source Title',
            ],
        ],
        'evidence' => ['source' => 'discogs_release_search'],
    ]);

    $response = $this->actingAs($user)->patchJson("/api/audio/metadata-proposals/{$proposal->id}", [
        'action' => 'apply',
        'fields' => ['title'],
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('proposal.status', 'applied');

    $file = $file->fresh();
    expect($file->title)->toBe('Canonical Source Title')
        ->and($file->metadata()->first()?->payload['audio']['aliases']['title'] ?? [])->toBe(['Custom Import Title']);
});

test('metadata proposal does not re-propose an embedded title alias', function () {
    config([
        'services.audio_metadata.acoustid_client_key' => null,
        'services.audio_metadata.discogs_token' => null,
    ]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Canonical Source Title',
        'filename' => 'custom-import-title.mp3',
    ]);
    $file->metadata()->create([
        'payload' => [
            'title' => 'Custom Import Title',
        ],
    ]);
    $file->metadataAliases()->create([
        'field' => 'title',
        'value' => 'Custom Import Title',
        'kind' => 'previous_import',
        'source' => 'atlas',
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('run.proposal_count', 0)
        ->assertJsonPath('proposal', null);
});

test('metadata proposal applies selected title and album aliases', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Theme from GTO',
    ]);
    $album = Album::factory()->create([
        'name' => 'GTO TV Animation Original Soundtrack',
        'normalized_name' => 'gto tv animation original soundtrack',
    ]);
    $file->albums()->sync([$album->id]);
    $run = AudioMetadataRun::query()->create([
        'user_id' => $user->id,
        'scope' => 'single',
        'source_filter' => 'local',
        'status' => 'completed',
        'total_files' => 1,
        'processed_files' => 1,
        'proposal_count' => 1,
        'options' => ['file_id' => $file->id],
    ]);
    $proposal = AudioMetadataProposal::query()->create([
        'audio_metadata_run_id' => $run->id,
        'file_id' => $file->id,
        'provider' => 'discogs_release',
        'status' => 'pending',
        'confidence' => 80,
        'current_values' => [
            'title' => 'Theme from GTO',
            'title_aliases' => [],
            'album' => 'GTO TV Animation Original Soundtrack',
            'album_aliases' => [],
        ],
        'proposed_values' => [
            'title' => 'The Theme From GTO',
            'title_aliases' => ['Theme from GTO'],
            'album' => 'TVアニメーション GTO オリジナルサウンドトラック',
            'album_aliases' => [
                'TV Animation GTO Original Soundtrack',
                'GTO TV Animation Original Soundtrack',
            ],
        ],
        'changes' => [
            'title' => [
                'current' => 'Theme from GTO',
                'proposed' => 'The Theme From GTO',
            ],
            'title_aliases' => [
                'current' => [],
                'proposed' => ['Theme from GTO'],
            ],
            'album' => [
                'current' => 'GTO TV Animation Original Soundtrack',
                'proposed' => 'TVアニメーション GTO オリジナルサウンドトラック',
            ],
            'album_aliases' => [
                'current' => [],
                'proposed' => [
                    'TV Animation GTO Original Soundtrack',
                    'GTO TV Animation Original Soundtrack',
                ],
            ],
        ],
        'evidence' => [
            'source' => 'discogs_release_search',
            'discogs_release_id' => '17124567',
        ],
    ]);

    $response = $this->actingAs($user)->patchJson("/api/audio/metadata-proposals/{$proposal->id}", [
        'action' => 'apply',
        'fields' => ['title', 'title_aliases', 'album', 'album_aliases'],
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('proposal.status', 'applied');

    $file = $file->fresh('albums');
    $canonicalAlbum = $file->albums->first();

    expect($file->title)->toBe('The Theme From GTO')
        ->and($canonicalAlbum?->name)->toBe('TVアニメーション GTO オリジナルサウンドトラック')
        ->and(DB::table('metadata_aliases')
            ->where('aliasable_type', File::class)
            ->where('aliasable_id', $file->id)
            ->where('field', 'title')
            ->pluck('value')
            ->all())->toBe(['Theme from GTO'])
        ->and(DB::table('metadata_aliases')
            ->where('aliasable_type', Album::class)
            ->where('aliasable_id', $canonicalAlbum?->id)
            ->where('field', 'name')
            ->orderBy('value')
            ->pluck('value')
            ->all())->toBe([
                'GTO TV Animation Original Soundtrack',
                'TV Animation GTO Original Soundtrack',
            ]);
});
