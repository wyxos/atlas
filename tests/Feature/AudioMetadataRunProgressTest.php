<?php

use App\Jobs\GenerateAudioMetadataRun;
use App\Models\AudioMetadataProposal;
use App\Models\AudioMetadataRun;
use App\Models\File;
use App\Models\User;
use App\Services\Audio\AudioMetadataProposalGenerator;
use App\Services\Audio\AudioMetadataProposalService;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

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
        fn (GenerateAudioMetadataRun $job): bool => $job->queue === 'audio-metadata'
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

    $this->mock(AudioMetadataProposalGenerator::class, function (MockInterface $mock): void {
        $mock->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function (
                AudioMetadataRun $run,
                File $file,
                User $user,
                ?callable $progress = null,
            ): AudioMetadataProposal {
                return AudioMetadataProposal::query()->create([
                    'audio_metadata_run_id' => $run->id,
                    'file_id' => $file->id,
                    'provider' => 'local',
                    'status' => 'pending',
                    'confidence' => 80,
                    'current_values' => [
                        'title' => 'raw filename',
                    ],
                    'proposed_values' => [
                        'title' => 'Tagged Title',
                    ],
                    'changes' => [
                        'title' => [
                            'current' => 'raw filename',
                            'proposed' => 'Tagged Title',
                        ],
                    ],
                    'evidence' => [
                        'source' => 'embedded_tags',
                    ],
                ]);
            });
    });

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
            && data_get($event, 'payload.proposal.is_compact') === true
            && data_get($event, 'payload.proposal.field_options') === []
            && data_get($event, 'payload.proposal.evidence.field_options') === null
    );
});

test('metadata run broadcast failures do not fail proposal generation', function () {
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

    $this->mock(AudioMetadataProposalGenerator::class, function (MockInterface $mock): void {
        $mock->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function (
                AudioMetadataRun $run,
                File $file,
                User $user,
                ?callable $progress = null,
            ): AudioMetadataProposal {
                return AudioMetadataProposal::query()->create([
                    'audio_metadata_run_id' => $run->id,
                    'file_id' => $file->id,
                    'provider' => 'local',
                    'status' => 'pending',
                    'confidence' => 80,
                    'current_values' => [
                        'title' => 'raw filename',
                    ],
                    'proposed_values' => [
                        'title' => 'Tagged Title',
                    ],
                    'changes' => [
                        'title' => [
                            'current' => 'raw filename',
                            'proposed' => 'Tagged Title',
                        ],
                    ],
                    'evidence' => [
                        'source' => 'embedded_tags',
                    ],
                ]);
            });
    });

    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->andThrow(new RuntimeException('Pusher error: Payload too large.'));
    $this->app->instance('events', $dispatcher);
    $this->app->instance(Dispatcher::class, $dispatcher);

    app(AudioMetadataProposalService::class)->processRun($run->id);

    $run->refresh();

    expect($run->status)->toBe('completed')
        ->and($run->processed_files)->toBe(1)
        ->and($run->proposal_count)->toBe(1)
        ->and($run->failed_files)->toBe(0)
        ->and($run->error)->toBeNull();
});

test('metadata run broadcasts current step labels while processing a file', function () {
    Event::fake();

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'raw filename',
        'filename' => 'raw-filename.mp3',
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

    $this->mock(AudioMetadataProposalGenerator::class, function (MockInterface $mock): void {
        $mock->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function (
                AudioMetadataRun $run,
                File $file,
                User $user,
                ?callable $progress = null,
            ): ?AudioMetadataProposal {
                if ($progress !== null) {
                    $progress('fingerprint', 'Generating audio fingerprint');
                }

                return null;
            });
    });

    app(AudioMetadataProposalService::class)->processRun($run->id);

    Event::assertDispatched(
        'App\\Events\\AudioMetadataRunUpdated',
        fn (object $event): bool => data_get($event, 'payload.run.status') === 'running'
            && data_get($event, 'payload.run.current_file_id') === $file->id
            && data_get($event, 'payload.run.current_step') === 'fingerprint'
            && data_get($event, 'payload.run.current_step_label') === 'Generating audio fingerprint'
    );
});

test('whole library metadata run request queues every audio source', function () {
    Queue::fake([GenerateAudioMetadataRun::class]);

    $user = User::factory()->create();
    File::factory()->create(['mime_type' => 'audio/mpeg', 'source' => 'local']);
    File::factory()->create(['mime_type' => 'audio/ogg', 'source' => 'spotify']);
    File::factory()->create(['mime_type' => 'image/jpeg', 'source' => 'local']);

    $response = $this->actingAs($user)->postJson('/api/audio/metadata-runs', [
        'scope' => 'whole_library',
        'source_filter' => 'local',
    ]);

    $response->assertAccepted()
        ->assertJsonPath('run.scope', 'all')
        ->assertJsonPath('run.source_filter', 'all')
        ->assertJsonPath('run.total_files', 2)
        ->assertJsonPath('run.processed_files', 0)
        ->assertJsonPath('run.progress_percent', 0)
        ->assertJsonPath('run.status', 'pending');

    Queue::assertPushed(
        GenerateAudioMetadataRun::class,
        fn (GenerateAudioMetadataRun $job): bool => $job->queue === 'audio-metadata'
    );
});

test('metadata run broadcasts percentage progress while processing multiple files', function () {
    Event::fake();

    $user = User::factory()->create();
    File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'first track',
        'filename' => 'first-track.mp3',
    ]);
    File::factory()->create([
        'source' => 'spotify',
        'mime_type' => 'audio/ogg',
        'title' => 'second track',
        'filename' => 'second-track.ogg',
    ]);
    File::factory()->create([
        'source' => 'local',
        'mime_type' => 'image/jpeg',
        'title' => 'cover',
        'filename' => 'cover.jpg',
    ]);
    $run = AudioMetadataRun::query()->create([
        'user_id' => $user->id,
        'scope' => 'all',
        'source_filter' => 'all',
        'status' => 'pending',
        'total_files' => 2,
        'options' => [
            'scope' => 'all',
            'source_filter' => 'all',
        ],
    ]);

    $this->mock(AudioMetadataProposalGenerator::class, function (MockInterface $mock): void {
        $mock->shouldReceive('generate')
            ->twice()
            ->andReturnNull();
    });

    app(AudioMetadataProposalService::class)->processRun($run->id);

    Event::assertDispatched(
        'App\\Events\\AudioMetadataRunUpdated',
        fn (object $event): bool => data_get($event, 'payload.run.status') === 'running'
            && data_get($event, 'payload.run.total_files') === 2
            && data_get($event, 'payload.run.processed_files') === 1
            && data_get($event, 'payload.run.progress_percent') === 50
    );
    Event::assertDispatched(
        'App\\Events\\AudioMetadataRunUpdated',
        fn (object $event): bool => data_get($event, 'payload.run.status') === 'completed'
            && data_get($event, 'payload.run.total_files') === 2
            && data_get($event, 'payload.run.processed_files') === 2
            && data_get($event, 'payload.run.progress_percent') === 100
    );
});
