<?php

use App\Models\File;
use App\Models\FileMetadata;
use App\Services\LibraryScans\MediaProbeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('audits imported videos that need streamable output without exposing file paths', function () {
    Storage::fake('atlas');
    app()->instance(MediaProbeService::class, new class extends MediaProbeService
    {
        public function probe(string $absolutePath): array
        {
            $path = str_replace('\\', '/', $absolutePath);

            return match (true) {
                str_contains($path, 'supported-source') => [
                    'streams' => [
                        ['codec_type' => 'video', 'codec_name' => 'h264'],
                    ],
                ],
                str_contains($path, 'unsupported-existing-source'),
                str_contains($path, 'unsupported-missing-source'),
                str_contains($path, 'download-source') => [
                    'streams' => [
                        ['codec_type' => 'video', 'codec_name' => 'hevc'],
                    ],
                ],
                default => [],
            };
        }
    });

    $supportedBytes = createAuditStoredVideo('imports/aa/aa/supported-source.mp4', 'supported-video');
    $unsupportedMissingBytes = createAuditStoredVideo('imports/bb/bb/unsupported-missing-source.mkv', 'unsupported-missing-video');
    $unsupportedExistingBytes = createAuditStoredVideo('imports/cc/cc/unsupported-existing-source.mkv', 'unsupported-existing-video');
    createAuditStoredVideo('downloads/dd/dd/download-source.mkv', 'download-video');

    createAuditFile('imports/aa/aa/supported-source.mp4', 'video/mp4');
    createAuditFile('imports/bb/bb/unsupported-missing-source.mkv', 'video/x-matroska');
    $unsupportedExisting = createAuditFile('imports/cc/cc/unsupported-existing-source.mkv', 'video/x-matroska');
    createAuditFile('downloads/dd/dd/download-source.mkv', 'video/x-matroska');
    createAuditFile('imports/ee/ee/missing-source.mkv', 'video/x-matroska');

    $streamablePath = 'imports/cc/cc/conversions/unsupported-existing-source.mp4';
    Storage::disk('atlas')->put($streamablePath, 'generated-streamable-video');
    FileMetadata::factory()->create([
        'file_id' => $unsupportedExisting->id,
        'payload' => [
            'conversions' => [
                'streamable_video' => $streamablePath,
            ],
        ],
    ]);

    $exitCode = Artisan::call('atlas:audit-streamable-videos', [
        '--imports' => true,
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0);
    $output = Artisan::output();
    $summary = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect($summary)->toMatchArray([
        'scope' => 'imports',
        'video_files_scanned' => 4,
        'source_files_found' => 3,
        'missing_source_files' => 1,
        'unknown_size_files' => 0,
        'browser_supported_files' => 1,
        'browser_supported_bytes' => $supportedBytes,
        'streamable_required_files' => 2,
        'streamable_required_bytes' => $unsupportedMissingBytes + $unsupportedExistingBytes,
        'streamable_required_with_output_files' => 1,
        'streamable_required_with_output_bytes' => $unsupportedExistingBytes,
        'streamable_required_without_output_files' => 1,
        'streamable_required_without_output_bytes' => $unsupportedMissingBytes,
        'stale_streamable_metadata_files' => 0,
        'probe_failures' => 0,
    ]);

    expect($output)
        ->not->toContain('supported-source')
        ->not->toContain('unsupported-missing-source')
        ->not->toContain('unsupported-existing-source')
        ->not->toContain('download-source')
        ->not->toContain('missing-source');
});

it('rejects unsupported audit scopes', function () {
    $exitCode = Artisan::call('atlas:audit-streamable-videos', ['--scope' => 'private']);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Invalid --scope. Use imports, downloads, or all.');
});

it('rejects conflicting audit scope flags', function () {
    $exitCode = Artisan::call('atlas:audit-streamable-videos', [
        '--imports' => true,
        '--downloads' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Choose only one of --imports, --downloads, or --all.');
});

function createAuditStoredVideo(string $path, string $contents): int
{
    Storage::disk('atlas')->put($path, $contents);

    return strlen($contents);
}

function createAuditFile(string $path, string $mimeType): File
{
    $extension = pathinfo($path, PATHINFO_EXTENSION);

    return File::factory()->create([
        'path' => $path,
        'filename' => 'audit-fixture.'.$extension,
        'ext' => $extension,
        'mime_type' => $mimeType,
        'size' => null,
        'preview_path' => null,
        'poster_path' => null,
        'downloaded' => str_starts_with($path, 'downloads/'),
        'imported_at' => str_starts_with($path, 'imports/') ? now() : null,
        'downloaded_at' => str_starts_with($path, 'downloads/') ? now() : null,
    ]);
}
