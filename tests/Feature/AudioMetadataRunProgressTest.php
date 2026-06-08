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
