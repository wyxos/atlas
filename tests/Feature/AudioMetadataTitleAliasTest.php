<?php

use App\Models\AudioMetadataProposal;
use App\Models\AudioMetadataRun;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
            'audio' => [
                'aliases' => [
                    'title' => ['Custom Import Title'],
                ],
            ],
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('run.proposal_count', 0)
        ->assertJsonPath('proposal', null);
});
