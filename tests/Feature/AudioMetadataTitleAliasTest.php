<?php

use App\Models\Album;
use App\Models\AudioMetadataProposal;
use App\Models\AudioMetadataRun;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('metadata proposal applies canonical title without preserving the old title as an alias', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Custom Import Title',
        'filename' => 'custom-import-title.mp3',
    ]);
    $file->metadata()->create([
        'payload' => [
            'title' => 'Custom Import Title',
        ],
    ]);
    $run = audioMetadataRun($user);
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

    $this->actingAs($user)->patchJson("/api/audio/metadata-proposals/{$proposal->id}", [
        'action' => 'apply',
        'fields' => ['title'],
    ])->assertSuccessful()
        ->assertJsonPath('proposal.status', 'applied');

    $file->refresh()->load('metadata');

    expect($file->title)->toBe('Canonical Source Title')
        ->and(data_get($file->metadata?->payload ?? [], 'audio.aliases'))->toBeNull()
        ->and(DB::table('metadata_aliases')->count())->toBe(0);
});

test('stale title alias rows do not suppress embedded tag proposals', function () {
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
    DB::table('metadata_aliases')->insert([
        'aliasable_type' => File::class,
        'aliasable_id' => $file->id,
        'field' => 'title',
        'value' => 'Custom Import Title',
        'normalized_value' => 'custom import title',
        'kind' => 'previous_import',
        'source' => 'atlas',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('run.proposal_count', 1)
        ->assertJsonPath('proposal.provider', 'local')
        ->assertJsonPath('proposal.proposed_values', [])
        ->assertJsonPath('proposal.field_options.title.0.value', 'Custom Import Title')
        ->assertJsonPath('proposal.field_options.title.0.recommended', false)
        ->assertJsonMissingPath('proposal.proposed_values.title_aliases');
});

test('metadata proposal ignores stale alias changes when applying canonical fields', function () {
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
    $run = audioMetadataRun($user);
    $proposal = AudioMetadataProposal::query()->create([
        'audio_metadata_run_id' => $run->id,
        'file_id' => $file->id,
        'provider' => 'discogs_release',
        'status' => 'pending',
        'confidence' => 80,
        'current_values' => [
            'title' => 'Theme from GTO',
            'album' => 'GTO TV Animation Original Soundtrack',
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

    $this->actingAs($user)->patchJson("/api/audio/metadata-proposals/{$proposal->id}", [
        'action' => 'apply',
        'fields' => ['title', 'album'],
    ])->assertSuccessful()
        ->assertJsonPath('proposal.status', 'applied');

    $file = $file->fresh('albums');
    $canonicalAlbum = $file->albums->first();

    expect($file->title)->toBe('The Theme From GTO')
        ->and($canonicalAlbum?->name)->toBe('TVアニメーション GTO オリジナルサウンドトラック')
        ->and(DB::table('metadata_aliases')->count())->toBe(0);
});

function audioMetadataRun(User $user): AudioMetadataRun
{
    return AudioMetadataRun::query()->create([
        'user_id' => $user->id,
        'scope' => 'single',
        'source_filter' => 'local',
        'status' => 'completed',
        'total_files' => 1,
        'processed_files' => 1,
        'proposal_count' => 1,
    ]);
}
