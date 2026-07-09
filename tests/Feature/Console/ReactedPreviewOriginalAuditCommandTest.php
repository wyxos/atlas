<?php

use App\Enums\MediaProcessorOperation;
use App\Enums\MediaProcessorTaskStatus;
use App\Models\File;
use App\Models\MediaProcessorTask;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File as FileFacade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('atlas');
});

it('reports only positively reacted previewable files downloaded inside the UTC window', function () {
    $affected = createReactedPreviewOriginalAuditFile([
        'downloaded_at' => '2026-05-28 12:00:00',
        'path' => 'downloads/private/missing-original.jpg',
        'filename' => 'private-missing-original.jpg',
        'url' => 'https://example.test/private-original.jpg',
        'referrer_url' => 'https://example.test/private-page',
    ], 'love');
    createReactedPreviewOriginalAuditFile([
        'downloaded_at' => '2026-05-25 23:59:59',
        'path' => 'downloads/private/outside-window.jpg',
    ], 'love');
    createReactedPreviewOriginalAuditFile([
        'downloaded_at' => '2026-05-30 23:59:59',
        'path' => 'downloads/private/negative-reaction.jpg',
    ], 'skip');
    createReactedPreviewOriginalAuditFile([
        'downloaded_at' => '2026-05-31 00:00:00',
        'path' => 'downloads/private/after-window.jpg',
    ], 'like');

    $report = runReactedPreviewOriginalAudit();

    expect($report['exit_code'])->toBe(0)
        ->and($report['records'])->toHaveCount(1)
        ->and($report['records'][0]['file_id'])->toBe((string) $affected->id)
        ->and($report['records'][0]['reaction_types'])->toBe('love')
        ->and($report['records'][0]['reason_codes'])->toContain('missing_disk_file');

    expect($report['csv'])
        ->not->toContain('private-missing-original')
        ->not->toContain('downloads/private')
        ->not->toContain('https://example.test');

    FileFacade::deleteDirectory($report['directory']);
});

it('excludes healthy originals and omits sensitive columns from the report', function () {
    $bytes = validReactedPreviewOriginalAuditJpeg();
    $healthy = createReactedPreviewOriginalAuditFile([
        'downloaded_at' => '2026-05-29 08:00:00',
        'path' => 'downloads/aa/bb/healthy-original.jpg',
        'filename' => 'healthy-private-name.jpg',
        'size' => strlen($bytes),
    ], 'funny');
    createReactedPreviewOriginalAuditFile([
        'downloaded_at' => '2026-05-29 09:00:00',
        'path' => 'downloads/aa/bb/missing-original.jpg',
        'filename' => 'missing-private-name.jpg',
    ], 'like');

    Storage::disk('atlas')->put($healthy->path, $bytes);

    $report = runReactedPreviewOriginalAudit();

    expect($report['records'])->toHaveCount(1)
        ->and($report['records'][0]['file_id'])->not->toBe((string) $healthy->id)
        ->and($report['header'])->toContain('path_present')
        ->and($report['header'])->not->toContain('path')
        ->and($report['header'])->not->toContain('url')
        ->and($report['header'])->not->toContain('filename')
        ->and($report['header'])->not->toContain('source_id');

    expect($report['csv'])
        ->not->toContain('healthy-private-name')
        ->not->toContain('missing-private-name')
        ->not->toContain('downloads/aa/bb');

    FileFacade::deleteDirectory($report['directory']);
});

it('reports healthy originals with stale active preview tasks', function () {
    $bytes = validReactedPreviewOriginalAuditJpeg();
    $stale = createReactedPreviewOriginalAuditFile([
        'downloaded_at' => '2026-05-29 08:00:00',
        'path' => 'downloads/aa/bb/stale-preview-original.jpg',
        'size' => strlen($bytes),
    ], 'love');
    $recent = createReactedPreviewOriginalAuditFile([
        'downloaded_at' => '2026-05-29 09:00:00',
        'path' => 'downloads/aa/bb/recent-preview-original.jpg',
        'size' => strlen($bytes),
    ], 'like');
    $terminal = createReactedPreviewOriginalAuditFile([
        'downloaded_at' => '2026-05-29 10:00:00',
        'path' => 'downloads/aa/bb/terminal-preview-original.jpg',
        'size' => strlen($bytes),
    ], 'funny');

    Storage::disk('atlas')->put($stale->path, $bytes);
    Storage::disk('atlas')->put($recent->path, $bytes);
    Storage::disk('atlas')->put($terminal->path, $bytes);

    createReactedPreviewOriginalAuditTask($stale, [
        'status' => MediaProcessorTaskStatus::SUBMITTING,
        'phase' => MediaProcessorTaskStatus::SUBMITTING,
        'created_at' => now()->subMinutes(61),
        'updated_at' => now()->subMinutes(61),
    ]);
    createReactedPreviewOriginalAuditTask($recent, [
        'status' => MediaProcessorTaskStatus::PROCESSING,
        'phase' => MediaProcessorTaskStatus::PROCESSING,
        'last_event_at' => now()->subMinutes(10),
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subMinutes(10),
    ]);
    createReactedPreviewOriginalAuditTask($terminal, [
        'status' => MediaProcessorTaskStatus::SUBMITTING,
        'phase' => MediaProcessorTaskStatus::SUBMITTING,
        'created_at' => now()->subHours(3),
        'updated_at' => now()->subHours(3),
    ]);
    createReactedPreviewOriginalAuditTask($terminal, [
        'status' => MediaProcessorTaskStatus::COMPLETED,
        'phase' => MediaProcessorTaskStatus::COMPLETED,
        'completed_at' => now()->subHours(2),
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);

    $staleTask = $stale->fresh()->latestPreviewMediaProcessorTask;

    expect($staleTask)->not->toBeNull()
        ->and($staleTask->status)->toBe(MediaProcessorTaskStatus::SUBMITTING)
        ->and($staleTask->created_at->lte(now()->subMinutes(60)))->toBeTrue();

    $report = runReactedPreviewOriginalAudit();

    expect($report['records'])->toHaveCount(1)
        ->and($report['records'][0]['file_id'])->toBe((string) $stale->id)
        ->and($report['records'][0]['reason_codes'])->toBe('stale_preview_task')
        ->and($report['records'][0]['preview_generation_status'])->toBe(MediaProcessorTaskStatus::SUBMITTING)
        ->and($report['records'][0]['recommended_action'])->toBe('retry_preview_generation')
        ->and($report['summary']['stale_after_minutes'])->toBe(60)
        ->and($report['summary']['reason_counts']['stale_preview_task'])->toBe(1);

    FileFacade::deleteDirectory($report['directory']);
});

it('preserves original repair reasons when a preview task is also stale', function () {
    $affected = createReactedPreviewOriginalAuditFile([
        'downloaded_at' => '2026-05-29 08:00:00',
        'path' => 'downloads/aa/bb/missing-stale-original.jpg',
    ], 'love');
    createReactedPreviewOriginalAuditTask($affected, [
        'status' => MediaProcessorTaskStatus::SUBMITTING,
        'phase' => MediaProcessorTaskStatus::SUBMITTING,
        'created_at' => now()->subMinutes(61),
        'updated_at' => now()->subMinutes(61),
    ]);

    $report = runReactedPreviewOriginalAudit();

    expect($report['records'])->toHaveCount(1)
        ->and($report['records'][0]['file_id'])->toBe((string) $affected->id)
        ->and($report['records'][0]['reason_codes'])->toBe('missing_disk_file|stale_preview_task')
        ->and($report['records'][0]['recommended_action'])->toBe('redownload_original');

    FileFacade::deleteDirectory($report['directory']);
});

function createReactedPreviewOriginalAuditTask(File $file, array $attributes = []): MediaProcessorTask
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

function createReactedPreviewOriginalAuditFile(array $attributes = [], string $reactionType = 'love'): File
{
    $file = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => 'sensitive-source-id',
        'mime_type' => 'image/jpeg',
        'downloaded' => true,
        'downloaded_at' => '2026-05-28 12:00:00',
        'path' => 'downloads/aa/bb/missing-original.jpg',
        'preview_path' => null,
        'poster_path' => null,
        'size' => 123,
        'not_found' => false,
        ...$attributes,
    ]);

    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => User::factory()->create()->id,
        'type' => $reactionType,
    ]);

    return $file;
}

/**
 * @return array{
 *     exit_code: int,
 *     directory: string,
 *     csv: string,
 *     header: list<string>,
 *     records: list<array<string, string>>,
 *     summary: array<string, mixed>
 * }
 */
function runReactedPreviewOriginalAudit(): array
{
    $directory = storage_path('framework/testing/reacted-preview-originals-'.Str::random(8));
    FileFacade::deleteDirectory($directory);

    $exitCode = Artisan::call('atlas:audit-reacted-preview-originals', [
        '--output' => $directory,
        '--json' => true,
    ]);

    $csvPath = $directory.DIRECTORY_SEPARATOR.'affected-records.csv';
    $summaryPath = $directory.DIRECTORY_SEPARATOR.'summary.json';

    expect(FileFacade::exists($csvPath))->toBeTrue()
        ->and(FileFacade::exists($summaryPath))->toBeTrue();

    $csv = FileFacade::get($csvPath);
    $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', trim($csv)) ?: []));
    $header = isset($lines[0]) ? str_getcsv($lines[0]) : [];
    $records = array_map(
        static fn (string $line): array => array_combine($header, str_getcsv($line)),
        array_slice($lines, 1),
    );
    $summary = json_decode(FileFacade::get($summaryPath), true, flags: JSON_THROW_ON_ERROR);

    return [
        'exit_code' => $exitCode,
        'directory' => $directory,
        'csv' => $csv,
        'header' => $header,
        'records' => $records,
        'summary' => $summary,
    ];
}

function validReactedPreviewOriginalAuditJpeg(): string
{
    return base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/ASP/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/ASP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/ISP/2gAMAwEAAgADAAAAEP/EFBQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QE//EFBQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QE//EFBABAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QE//Z', true);
}
