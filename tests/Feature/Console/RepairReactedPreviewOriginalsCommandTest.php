<?php

use App\Enums\DownloadTransferStatus;
use App\Enums\MediaProcessorOperation;
use App\Enums\MediaProcessorTaskStatus;
use App\Jobs\DownloadFile;
use App\Jobs\GenerateFilePreviewAssets;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\MediaProcessorTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File as FileFacade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('atlas');
    $this->repairReportDirectory = storage_path('framework/testing/reacted-preview-repair-'.Str::random(8));
    FileFacade::deleteDirectory($this->repairReportDirectory);
    FileFacade::ensureDirectoryExists($this->repairReportDirectory);
});

afterEach(function (): void {
    FileFacade::deleteDirectory($this->repairReportDirectory);
});

it('queues a healthy original for preview regeneration and supersedes its stale task', function () {
    Bus::fake();

    $bytes = validReactedPreviewRepairJpeg();
    $file = reactedPreviewRepairFile([
        'path' => 'downloads/aa/bb/healthy-original.jpg',
        'size' => strlen($bytes),
    ]);
    Storage::disk('atlas')->put($file->path, $bytes);
    $staleTask = reactedPreviewRepairTask($file, [
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);
    $report = writeReactedPreviewRepairReport($this->repairReportDirectory, [[
        'file_id' => $file->id,
        'reason_codes' => 'stale_preview_task',
        'recommended_action' => 'retry_preview_generation',
    ]]);

    $this->artisan('atlas:repair-reacted-preview-originals', [
        '--report' => $report,
        '--max-downloads' => 1,
        '--max-previews' => 1,
    ])->assertExitCode(0);

    Bus::assertDispatched(GenerateFilePreviewAssets::class, fn (GenerateFilePreviewAssets $job): bool => $job->fileId === $file->id && $job->force);
    Bus::assertNotDispatched(DownloadFile::class);
    expect($staleTask->fresh())
        ->status->toBe(MediaProcessorTaskStatus::FAILED)
        ->error_code->toBe('preview_task_stale_superseded');
});

it('queues unhealthy originals through the normal download path up to the download cap', function () {
    Bus::fake();
    Http::fake([
        'https://example.test/source/*' => Http::response('', 200),
    ]);

    $first = reactedPreviewRepairFile([
        'path' => 'downloads/aa/bb/missing-first.jpg',
        'referrer_url' => 'https://example.test/source/first',
    ]);
    $second = reactedPreviewRepairFile([
        'path' => 'downloads/aa/bb/missing-second.jpg',
        'referrer_url' => 'https://example.test/source/second',
    ]);
    $report = writeReactedPreviewRepairReport($this->repairReportDirectory, [
        [
            'file_id' => $first->id,
            'reason_codes' => 'missing_disk_file|stale_preview_task',
            'recommended_action' => 'redownload_original',
        ],
        [
            'file_id' => $second->id,
            'reason_codes' => 'size_mismatch|stale_preview_task',
            'recommended_action' => 'redownload_original',
        ],
    ]);

    $this->artisan('atlas:repair-reacted-preview-originals', [
        '--report' => $report,
        '--max-downloads' => 1,
        '--max-previews' => 0,
    ])->assertExitCode(0);

    Bus::assertDispatchedTimes(DownloadFile::class, 1);
    Bus::assertDispatched(DownloadFile::class, fn (DownloadFile $job): bool => $job->fileId === $first->id);
    Bus::assertNotDispatched(GenerateFilePreviewAssets::class);
    expect($first->fresh())
        ->downloaded->toBeFalse()
        ->path->toBeNull()
        ->and($second->fresh())
        ->downloaded->toBeTrue()
        ->path->toBe('downloads/aa/bb/missing-second.jpg');
});

it('does not reset or queue an original when the download cap is already full', function () {
    Bus::fake();

    $busyFile = reactedPreviewRepairFile();
    DownloadTransfer::query()->create([
        'file_id' => $busyFile->id,
        'url' => 'https://example.test/busy.jpg',
        'domain' => 'example.test',
        'status' => DownloadTransferStatus::DOWNLOADING,
    ]);
    $candidate = reactedPreviewRepairFile([
        'path' => 'downloads/aa/bb/missing-capacity.jpg',
    ]);
    $report = writeReactedPreviewRepairReport($this->repairReportDirectory, [[
        'file_id' => $candidate->id,
        'reason_codes' => 'missing_disk_file|stale_preview_task',
        'recommended_action' => 'redownload_original',
    ]]);

    $this->artisan('atlas:repair-reacted-preview-originals', [
        '--report' => $report,
        '--max-downloads' => 1,
        '--max-previews' => 0,
    ])->assertExitCode(0);

    Bus::assertNotDispatched(DownloadFile::class);
    expect($candidate->fresh())
        ->downloaded->toBeTrue()
        ->path->toBe('downloads/aa/bb/missing-capacity.jpg');
});

it('does not supersede or retry a stale task when fresh processor capacity is full', function () {
    Bus::fake();

    $bytes = validReactedPreviewRepairJpeg();
    $candidate = reactedPreviewRepairFile([
        'path' => 'downloads/aa/bb/stale-capacity.jpg',
        'size' => strlen($bytes),
    ]);
    Storage::disk('atlas')->put($candidate->path, $bytes);
    $staleTask = reactedPreviewRepairTask($candidate, [
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);
    $busyFile = reactedPreviewRepairFile();
    reactedPreviewRepairTask($busyFile, [
        'status' => MediaProcessorTaskStatus::PROCESSING,
        'phase' => MediaProcessorTaskStatus::PROCESSING,
        'last_event_at' => now(),
    ]);
    $report = writeReactedPreviewRepairReport($this->repairReportDirectory, [[
        'file_id' => $candidate->id,
        'reason_codes' => 'stale_preview_task',
        'recommended_action' => 'retry_preview_generation',
    ]]);

    $this->artisan('atlas:repair-reacted-preview-originals', [
        '--report' => $report,
        '--max-downloads' => 0,
        '--max-previews' => 1,
    ])->assertExitCode(0);

    Bus::assertNotDispatched(GenerateFilePreviewAssets::class);
    expect($staleTask->fresh())
        ->status->toBe(MediaProcessorTaskStatus::SUBMITTING)
        ->error_code->toBeNull();
});

it('does not dispatch the same report record twice across command restarts', function () {
    Bus::fake();

    $bytes = validReactedPreviewRepairJpeg();
    $file = reactedPreviewRepairFile([
        'path' => 'downloads/aa/bb/restart-safe.jpg',
        'size' => strlen($bytes),
    ]);
    Storage::disk('atlas')->put($file->path, $bytes);
    reactedPreviewRepairTask($file, [
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);
    $report = writeReactedPreviewRepairReport($this->repairReportDirectory, [[
        'file_id' => $file->id,
        'reason_codes' => 'stale_preview_task',
        'recommended_action' => 'retry_preview_generation',
    ]]);
    $arguments = [
        '--report' => $report,
        '--max-downloads' => 0,
        '--max-previews' => 1,
    ];

    $this->artisan('atlas:repair-reacted-preview-originals', $arguments)->assertExitCode(0);
    $this->artisan('atlas:repair-reacted-preview-originals', $arguments)->assertExitCode(0);

    Bus::assertDispatchedTimes(GenerateFilePreviewAssets::class, 1);
    $statePath = $this->repairReportDirectory.DIRECTORY_SEPARATOR.'repair-state.json';
    expect(FileFacade::exists($statePath))->toBeTrue();
    expect(FileFacade::get($statePath))
        ->not->toContain('https://')
        ->not->toContain('downloads/')
        ->not->toContain('filename')
        ->not->toContain('source_id');
});

it('keeps watch mode within the preview cap while queued work remains pending', function () {
    Queue::fake();

    $bytes = validReactedPreviewRepairJpeg();
    $first = reactedPreviewRepairFile([
        'path' => 'downloads/aa/bb/watch-first.jpg',
        'size' => strlen($bytes),
    ]);
    $second = reactedPreviewRepairFile([
        'path' => 'downloads/aa/bb/watch-second.jpg',
        'size' => strlen($bytes),
    ]);
    Storage::disk('atlas')->put($first->path, $bytes);
    Storage::disk('atlas')->put($second->path, $bytes);
    reactedPreviewRepairTask($first, [
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);
    reactedPreviewRepairTask($second, [
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);
    $report = writeReactedPreviewRepairReport($this->repairReportDirectory, [
        [
            'file_id' => $first->id,
            'reason_codes' => 'stale_preview_task',
            'recommended_action' => 'retry_preview_generation',
        ],
        [
            'file_id' => $second->id,
            'reason_codes' => 'stale_preview_task',
            'recommended_action' => 'retry_preview_generation',
        ],
    ]);

    $this->artisan('atlas:repair-reacted-preview-originals', [
        '--report' => $report,
        '--max-downloads' => 0,
        '--max-previews' => 1,
        '--watch' => true,
        '--poll-seconds' => 1,
        '--max-runtime' => 1,
    ])->assertExitCode(0);

    Queue::assertPushed(GenerateFilePreviewAssets::class, 1);
});

it('keeps dry runs free of dispatches task mutations and repair state', function () {
    Bus::fake();

    $bytes = validReactedPreviewRepairJpeg();
    $file = reactedPreviewRepairFile([
        'path' => 'downloads/aa/bb/dry-run.jpg',
        'size' => strlen($bytes),
    ]);
    Storage::disk('atlas')->put($file->path, $bytes);
    $staleTask = reactedPreviewRepairTask($file, [
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);
    $report = writeReactedPreviewRepairReport($this->repairReportDirectory, [[
        'file_id' => $file->id,
        'reason_codes' => 'stale_preview_task',
        'recommended_action' => 'retry_preview_generation',
    ]]);

    $this->artisan('atlas:repair-reacted-preview-originals', [
        '--report' => $report,
        '--dry-run' => true,
    ])->assertExitCode(0);

    Bus::assertNothingDispatched();
    expect($staleTask->fresh()->status)->toBe(MediaProcessorTaskStatus::SUBMITTING)
        ->and(FileFacade::exists($this->repairReportDirectory.DIRECTORY_SEPARATOR.'repair-state.json'))->toBeFalse();
});

function reactedPreviewRepairFile(array $attributes = []): File
{
    $token = Str::lower(Str::random(10));

    return File::factory()->create([
        'source' => 'CivitAI',
        'mime_type' => 'image/jpeg',
        'downloaded' => true,
        'downloaded_at' => '2026-05-28 12:00:00',
        'path' => 'downloads/aa/bb/missing-original.jpg',
        'preview_path' => null,
        'poster_path' => null,
        'size' => 123,
        'url' => "https://example.test/source/{$token}.jpg",
        'referrer_url' => "https://example.test/source/{$token}",
        'not_found' => false,
        ...$attributes,
    ]);
}

function reactedPreviewRepairTask(File $file, array $attributes = []): MediaProcessorTask
{
    $taskAttributes = [
        'id' => (string) Str::uuid(),
        'file_id' => $file->id,
        'operation' => MediaProcessorOperation::IMAGE_PREVIEW,
        'status' => MediaProcessorTaskStatus::SUBMITTING,
        'phase' => MediaProcessorTaskStatus::SUBMITTING,
        'storage_profile' => 'atlas',
        'input_path' => $file->path,
        'attempts' => 1,
        ...$attributes,
    ];
    $createdAt = $taskAttributes['created_at'] ?? now();
    $updatedAt = $taskAttributes['updated_at'] ?? $createdAt;
    unset($taskAttributes['created_at'], $taskAttributes['updated_at']);

    $task = MediaProcessorTask::query()->create($taskAttributes);
    $task->timestamps = false;
    $task->forceFill([
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
    ])->saveQuietly();

    return $task;
}

/**
 * @param  list<array{file_id: int, reason_codes: string, recommended_action: string}>  $records
 */
function writeReactedPreviewRepairReport(string $directory, array $records): string
{
    $path = $directory.DIRECTORY_SEPARATOR.'affected-records.csv';
    $stream = fopen('php://temp', 'w+b');
    expect($stream)->not->toBeFalse();

    fputcsv($stream, ['file_id', 'reason_codes', 'recommended_action']);
    foreach ($records as $record) {
        fputcsv($stream, [$record['file_id'], $record['reason_codes'], $record['recommended_action']]);
    }

    rewind($stream);
    FileFacade::put($path, stream_get_contents($stream));
    fclose($stream);

    return $path;
}

function validReactedPreviewRepairJpeg(): string
{
    return base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/ASP/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/ASP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/ISP/2gAMAwEAAgADAAAAEP/EFBQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QE//EFBQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QE//EFBABAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QE//Z', true);
}
