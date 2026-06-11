<?php

use App\Jobs\GenerateAudioMetadataRun;
use App\Models\AudioMetadataRun;
use App\Models\File;
use App\Models\User;
use App\Services\Audio\AudioMetadataProposalGenerator;
use App\Services\Audio\AudioMetadataProposalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('active batch metadata run endpoint restores the latest non terminal batch run', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    AudioMetadataRun::query()->create([
        'user_id' => $otherUser->id,
        'scope' => 'all',
        'source_filter' => 'all',
        'status' => 'running',
        'total_files' => 40,
        'processed_files' => 12,
    ]);
    AudioMetadataRun::query()->create([
        'user_id' => $user->id,
        'scope' => 'single',
        'source_filter' => 'local',
        'status' => 'running',
        'total_files' => 1,
        'processed_files' => 0,
    ]);
    AudioMetadataRun::query()->create([
        'user_id' => $user->id,
        'scope' => 'all',
        'source_filter' => 'all',
        'status' => 'completed',
        'total_files' => 20,
        'processed_files' => 20,
    ]);
    $activeRun = AudioMetadataRun::query()->create([
        'user_id' => $user->id,
        'scope' => 'all',
        'source_filter' => 'all',
        'status' => 'running',
        'total_files' => 20,
        'processed_files' => 7,
        'options' => [
            'progress' => [
                'file_id' => 321,
                'step' => 'discogs',
                'label' => 'Searching Discogs release data',
            ],
        ],
    ]);

    $this->actingAs($user)
        ->getJson('/api/audio/metadata-runs/active')
        ->assertSuccessful()
        ->assertJsonPath('run.id', $activeRun->id)
        ->assertJsonPath('run.status', 'running')
        ->assertJsonPath('run.processed_files', 7)
        ->assertJsonPath('run.progress_percent', 35)
        ->assertJsonPath('run.current_step_label', 'Searching Discogs release data');
});

test('batch metadata run can be paused resumed and canceled', function () {
    Queue::fake([GenerateAudioMetadataRun::class]);

    $user = User::factory()->create();
    $run = AudioMetadataRun::query()->create([
        'user_id' => $user->id,
        'scope' => 'all',
        'source_filter' => 'all',
        'status' => 'running',
        'total_files' => 10,
        'processed_files' => 4,
    ]);

    $this->actingAs($user)
        ->postJson("/api/audio/metadata-runs/{$run->id}/pause")
        ->assertSuccessful()
        ->assertJsonPath('run.status', 'paused')
        ->assertJsonPath('run.processed_files', 4);

    $this->actingAs($user)
        ->postJson("/api/audio/metadata-runs/{$run->id}/resume")
        ->assertAccepted()
        ->assertJsonPath('run.status', 'pending')
        ->assertJsonPath('run.processed_files', 4);

    Queue::assertPushed(GenerateAudioMetadataRun::class);

    $this->actingAs($user)
        ->postJson("/api/audio/metadata-runs/{$run->id}/cancel")
        ->assertSuccessful()
        ->assertJsonPath('run.status', 'canceled')
        ->assertJsonPath('run.finished_at', fn (?string $value): bool => $value !== null);
});

test('paused batch metadata run stops after the current file and keeps its resume cursor', function () {
    $user = User::factory()->create();
    $firstFile = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'first',
    ]);
    File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'second',
    ]);
    $run = AudioMetadataRun::query()->create([
        'user_id' => $user->id,
        'scope' => 'all',
        'source_filter' => 'local',
        'status' => 'pending',
        'total_files' => 2,
        'processed_files' => 0,
    ]);

    $this->mock(AudioMetadataProposalGenerator::class, function (MockInterface $mock): void {
        $mock->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function (AudioMetadataRun $run): null {
                $run->forceFill(['status' => 'paused'])->save();

                return null;
            });
    });

    app(AudioMetadataProposalService::class)->processRun($run->id);

    $run->refresh();

    expect($run->status)->toBe('paused')
        ->and($run->processed_files)->toBe(1)
        ->and(data_get($run->options, 'last_processed_file_id'))->toBe($firstFile->id)
        ->and($run->finished_at)->toBeNull();
});

test('resumed batch metadata run continues after the last processed file', function () {
    $user = User::factory()->create();
    $firstFile = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'first',
    ]);
    $secondFile = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'second',
    ]);
    $run = AudioMetadataRun::query()->create([
        'user_id' => $user->id,
        'scope' => 'all',
        'source_filter' => 'local',
        'status' => 'pending',
        'total_files' => 2,
        'processed_files' => 1,
        'options' => [
            'last_processed_file_id' => $firstFile->id,
        ],
    ]);

    $this->mock(AudioMetadataProposalGenerator::class, function (MockInterface $mock) use ($secondFile): void {
        $mock->shouldReceive('generate')
            ->once()
            ->withArgs(fn (AudioMetadataRun $run, File $file): bool => $file->is($secondFile))
            ->andReturnNull();
    });

    app(AudioMetadataProposalService::class)->processRun($run->id);

    $run->refresh();

    expect($run->status)->toBe('completed')
        ->and($run->processed_files)->toBe(2)
        ->and(data_get($run->options, 'last_processed_file_id'))->toBe($secondFile->id)
        ->and($run->finished_at)->not->toBeNull();
});
