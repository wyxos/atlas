<?php

use App\Enums\MediaProcessorOperation;
use App\Enums\MediaProcessorTaskStatus;
use App\Jobs\GenerateFileStreamableVideo;
use App\Models\File;
use App\Models\MediaProcessorTask;
use App\Services\LibraryScans\LibraryScanMediaProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('requests a streamable conversion for a downloaded video', function () {
    $file = File::factory()->create([
        'path' => 'downloads/aa/bb/original.mp4',
        'downloaded' => true,
        'mime_type' => 'video/mp4',
    ]);
    $processor = Mockery::mock(LibraryScanMediaProcessor::class);
    $processor->shouldReceive('createStreamableVideo')
        ->once()
        ->withArgs(fn (File $candidate): bool => $candidate->is($file))
        ->andReturn(['remote_task_id' => (string) Str::uuid()]);

    (new GenerateFileStreamableVideo($file->id))->handle($processor);
});

it('does not request a duplicate conversion while one is active', function () {
    $file = File::factory()->create([
        'path' => 'downloads/aa/bb/original.mp4',
        'downloaded' => true,
        'mime_type' => 'video/mp4',
    ]);
    MediaProcessorTask::query()->create([
        'id' => (string) Str::uuid(),
        'file_id' => $file->id,
        'operation' => MediaProcessorOperation::STREAMABLE_VIDEO,
        'status' => MediaProcessorTaskStatus::PROCESSING,
        'phase' => 'processing',
        'progress' => 25,
        'storage_profile' => 'atlas-local',
        'input_path' => $file->path,
        'output_paths' => [
            'streamable_video' => 'downloads/aa/bb/conversions/original.mp4',
        ],
    ]);
    $processor = Mockery::mock(LibraryScanMediaProcessor::class);
    $processor->shouldNotReceive('createStreamableVideo');

    (new GenerateFileStreamableVideo($file->id))->handle($processor);
});
