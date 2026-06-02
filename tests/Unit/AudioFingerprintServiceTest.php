<?php

use App\Enums\MediaProcessorOperation;
use App\Enums\MediaProcessorTaskStatus;
use App\Models\File;
use App\Models\MediaProcessorTask;
use App\Services\Audio\AudioFingerprintService;
use App\Services\MediaProcessing\MediaProcessorPathValidator;
use App\Services\MediaProcessing\RemoteMediaProcessorClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('uses the remote media processor for managed audio fingerprints', function () {
    config([
        'services.audio_metadata.local_fingerprinting_enabled' => false,
        'services.audio_metadata.remote_fingerprint_timeout_seconds' => 1,
        'services.audio_metadata.remote_fingerprint_poll_milliseconds' => 50,
    ]);

    $file = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'path' => 'imports/audio/source.mp3',
    ]);

    $task = MediaProcessorTask::query()->create([
        'id' => '11111111-1111-4111-8111-111111111111',
        'file_id' => $file->id,
        'operation' => MediaProcessorOperation::AUDIO_FINGERPRINT,
        'status' => MediaProcessorTaskStatus::COMPLETED,
        'phase' => 'completed',
        'progress' => 100,
        'processor_url' => 'https://processor.test',
        'storage_profile' => 'atlas-local',
        'atlas_instance' => 'testing',
        'input_path' => 'imports/audio/source.mp3',
        'output_paths' => [],
        'options' => [],
        'result' => [
            'metadata' => [
                'duration_seconds' => 181,
                'engine' => 'chromaprint',
                'fingerprint' => 'remote-fingerprint',
            ],
            'output_paths' => [],
        ],
        'attempts' => 1,
        'submitted_at' => now(),
        'completed_at' => now(),
        'last_event_at' => now(),
    ]);

    $remote = Mockery::mock(RemoteMediaProcessorClient::class, function (MockInterface $mock) use ($file, $task): void {
        $mock->shouldReceive('enabled')->once()->andReturn(true);
        $mock->shouldReceive('submit')
            ->once()
            ->with($file, MediaProcessorOperation::AUDIO_FINGERPRINT, 'imports/audio/source.mp3', [], ['engine' => 'chromaprint'])
            ->andReturn($task);
    });

    $fingerprint = new AudioFingerprintService($remote, app(MediaProcessorPathValidator::class))->forFile($file);

    expect($fingerprint)->not->toBeNull()
        ->and($fingerprint?->fingerprint)->toBe('remote-fingerprint')
        ->and($fingerprint?->durationSeconds)->toBe(181)
        ->and($fingerprint?->path)->toBe('imports/audio/source.mp3')
        ->and($task->fresh()?->result['metadata']['fingerprint'] ?? null)->toBeNull()
        ->and($task->fresh()?->result['metadata']['fingerprint_size'] ?? null)->toBe(18);
});

it('does not submit unmanaged paths to the remote processor', function () {
    config([
        'services.audio_metadata.local_fingerprinting_enabled' => false,
    ]);

    $file = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'path' => 'C:/outside-atlas/source.mp3',
    ]);

    $remote = Mockery::mock(RemoteMediaProcessorClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('enabled')->once()->andReturn(true);
        $mock->shouldReceive('submit')->never();
    });

    expect(new AudioFingerprintService($remote, app(MediaProcessorPathValidator::class))->forFile($file))->toBeNull();
});
