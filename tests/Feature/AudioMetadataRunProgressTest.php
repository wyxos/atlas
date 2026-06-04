<?php

use App\Jobs\GenerateAudioMetadataRun;
use App\Models\AudioMetadataProposal;
use App\Models\AudioMetadataRun;
use App\Models\File;
use App\Models\User;
use App\Services\Audio\AudioMetadataProposalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('single local metadata run queues work without blocking the request when the queue is async', function () {
    Queue::fake([GenerateAudioMetadataRun::class]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'raw filename',
        'filename' => 'raw-filename.mp3',
    ]);
    $file->metadata()->create([
        'payload' => [
            'title' => 'Tagged Title',
            'artist' => 'Tagged Artist',
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('run.scope', 'single')
        ->assertJsonPath('run.status', 'pending')
        ->assertJsonPath('run.total_files', 1)
        ->assertJsonPath('run.processed_files', 0)
        ->assertJsonPath('proposal', null);

    Queue::assertPushed(
        GenerateAudioMetadataRun::class,
        fn (GenerateAudioMetadataRun $job): bool => $job->queue === 'library-scans'
    );

    expect(AudioMetadataProposal::query()->where('file_id', $file->id)->count())->toBe(0);
});

test('metadata run broadcasts progress snapshots while processing', function () {
    Event::fake();

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'raw filename',
        'filename' => 'raw-filename.mp3',
    ]);
    $file->metadata()->create([
        'payload' => [
            'title' => 'Tagged Title',
            'artist' => 'Tagged Artist',
        ],
    ]);
    $run = AudioMetadataRun::query()->create([
        'user_id' => $user->id,
        'scope' => 'single',
        'source_filter' => 'local',
        'status' => 'pending',
        'total_files' => 1,
        'options' => [
            'file_id' => (int) $file->id,
        ],
    ]);

    app(AudioMetadataProposalService::class)->processRun($run->id);

    Event::assertDispatched(
        'App\\Events\\AudioMetadataRunUpdated',
        fn (object $event): bool => data_get($event, 'payload.run.status') === 'running'
            && data_get($event, 'payload.run.processed_files') === 0
    );
    Event::assertDispatched(
        'App\\Events\\AudioMetadataRunUpdated',
        fn (object $event): bool => data_get($event, 'payload.run.status') === 'completed'
            && data_get($event, 'payload.run.processed_files') === 1
            && data_get($event, 'payload.proposal.proposed_values.title') === 'Tagged Title'
    );
});
