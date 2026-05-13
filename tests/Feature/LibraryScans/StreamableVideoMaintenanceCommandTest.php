<?php

use App\Enums\LibraryScanMediaTask as LibraryMediaTask;
use App\Enums\MediaProcessorOperation;
use App\Enums\MediaProcessorTaskStatus;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\LibraryScanItem;
use App\Models\LibraryScanMediaTask;
use App\Models\MediaProcessorTask;
use App\Services\LibraryScans\MediaProbeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('deletes generated streamable output for silent browser-supported originals', function () {
    Storage::fake('atlas');
    app()->instance(MediaProbeService::class, new class extends MediaProbeService
    {
        public function probe(string $absolutePath): array
        {
            return [
                'streams' => [
                    ['codec_type' => 'video', 'codec_name' => 'h264'],
                ],
            ];
        }
    });

    $hash = str_repeat('a', 40);
    $sourcePath = "downloads/aa/aa/{$hash}.mp4";
    $streamablePath = "downloads/aa/aa/conversions/{$hash}.mp4";
    $normalizedAudioPath = "downloads/aa/aa/conversions/{$hash}.mp3";

    Storage::disk('atlas')->put($sourcePath, 'source-video');
    Storage::disk('atlas')->put($streamablePath, 'generated-video');
    $file = File::factory()->create([
        'path' => $sourcePath,
        'mime_type' => 'video/mp4',
    ]);
    $metadata = FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => [
            'conversions' => [
                'streamable_video' => $streamablePath,
                'normalized_audio' => $normalizedAudioPath,
            ],
        ],
    ]);

    $this->artisan('atlas:maintain-streamable-videos --delete-supported-outputs --apply --chunk=1')
        ->assertSuccessful();

    expect(Storage::disk('atlas')->exists($streamablePath))->toBeFalse()
        ->and($metadata->fresh()?->payload)->toBe([
            'conversions' => [
                'normalized_audio' => $normalizedAudioPath,
            ],
        ]);
});

it('dry-runs streamable output cleanup without deleting files or metadata', function () {
    Storage::fake('atlas');
    app()->instance(MediaProbeService::class, new class extends MediaProbeService
    {
        public function probe(string $absolutePath): array
        {
            return [
                'streams' => [
                    ['codec_type' => 'video', 'codec_name' => 'h264'],
                ],
            ];
        }
    });

    $hash = str_repeat('b', 40);
    $sourcePath = "downloads/bb/bb/{$hash}.mp4";
    $streamablePath = "downloads/bb/bb/conversions/{$hash}.mp4";

    Storage::disk('atlas')->put($sourcePath, 'source-video');
    Storage::disk('atlas')->put($streamablePath, 'generated-video');
    $file = File::factory()->create([
        'path' => $sourcePath,
        'mime_type' => 'video/mp4',
    ]);
    $metadata = FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => [
            'conversions' => [
                'streamable_video' => $streamablePath,
            ],
        ],
    ]);

    $this->artisan('atlas:maintain-streamable-videos --delete-supported-outputs --dry-run --chunk=1')
        ->assertSuccessful();

    expect(Storage::disk('atlas')->exists($streamablePath))->toBeTrue()
        ->and($metadata->fresh()?->payload)->toBe([
            'conversions' => [
                'streamable_video' => $streamablePath,
            ],
        ]);
});

it('cancels active streamable Atlas task records', function () {
    $file = File::factory()->create([
        'path' => 'downloads/cc/cc/'.str_repeat('c', 40).'.mp4',
    ]);
    $item = LibraryScanItem::factory()->create(['file_id' => $file->id]);
    $libraryTask = LibraryScanMediaTask::factory()->create([
        'library_scan_item_id' => $item->id,
        'file_id' => $file->id,
        'type' => LibraryMediaTask::TASK_VIDEO_STREAMABLE,
        'status' => LibraryMediaTask::STATUS_PENDING,
    ]);
    $remoteTask = MediaProcessorTask::query()->create([
        'id' => (string) Str::uuid(),
        'file_id' => $file->id,
        'library_scan_media_task_id' => $libraryTask->id,
        'operation' => MediaProcessorOperation::STREAMABLE_VIDEO,
        'status' => MediaProcessorTaskStatus::QUEUED,
        'phase' => 'queued',
        'progress' => 0,
        'storage_profile' => 'atlas-local',
        'input_path' => (string) $file->path,
        'output_paths' => [],
    ]);

    $this->artisan('atlas:maintain-streamable-videos --cancel-jobs --apply')
        ->assertSuccessful();

    expect($libraryTask->fresh()?->status)->toBe(LibraryMediaTask::STATUS_CANCELED)
        ->and($libraryTask->fresh()?->phase)->toBe(LibraryMediaTask::PHASE_CANCELED)
        ->and($remoteTask->fresh()?->status)->toBe(MediaProcessorTaskStatus::FAILED)
        ->and($remoteTask->fresh()?->phase)->toBe('canceled');
});
