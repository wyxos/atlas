<?php

namespace App\Console\Commands;

use App\Enums\LibraryScanMediaTask as LibraryScanMediaTaskEnum;
use App\Enums\MediaProcessorOperation;
use App\Enums\MediaProcessorTaskStatus;
use App\Models\FileMetadata;
use App\Models\LibraryScanMediaTask;
use App\Models\MediaProcessorTask;
use App\Services\LibraryScans\BrowserVideoSupport;
use App\Services\LibraryScans\MediaProbeService;
use App\Support\AtlasPathResolver;
use App\Support\AtlasStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MaintainStreamableVideos extends Command
{
    protected $signature = 'atlas:maintain-streamable-videos
        {--cancel-jobs : Cancel active Atlas streamable-video task records}
        {--delete-supported-outputs : Delete generated streamable outputs when the original is already browser-supported}
        {--chunk=500 : Chunk size for metadata scanning}
        {--apply : Apply changes}
        {--dry-run : Force dry-run mode even when --apply is present}';

    protected $description = 'Cancel obsolete streamable-video work and clean generated copies for browser-supported originals.';

    public function handle(BrowserVideoSupport $browserVideoSupport, MediaProbeService $probe): int
    {
        $cancelJobs = (bool) $this->option('cancel-jobs');
        $deleteSupportedOutputs = (bool) $this->option('delete-supported-outputs');

        if (! $cancelJobs && ! $deleteSupportedOutputs) {
            $this->warn('No action selected. Use --cancel-jobs, --delete-supported-outputs, or both.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply') && ! (bool) $this->option('dry-run');
        $this->info('Mode: '.($apply ? 'apply' : 'dry-run'));

        if ($cancelJobs) {
            $this->printSummary('Job Records', $this->cancelJobRecords($apply));
        }

        if ($deleteSupportedOutputs) {
            $this->printSummary(
                'Generated Outputs',
                $this->deleteSupportedOutputs($browserVideoSupport, $probe, $apply),
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function cancelJobRecords(bool $apply): array
    {
        $libraryScanQuery = LibraryScanMediaTask::query()
            ->where('type', LibraryScanMediaTaskEnum::TASK_VIDEO_STREAMABLE)
            ->whereNotIn('status', LibraryScanMediaTaskEnum::terminal());

        $mediaProcessorQuery = MediaProcessorTask::query()
            ->where('operation', MediaProcessorOperation::STREAMABLE_VIDEO)
            ->whereIn('status', MediaProcessorTaskStatus::active());

        $summary = [
            'library_scan_media_tasks' => (int) $libraryScanQuery->count(),
            'media_processor_tasks' => (int) $mediaProcessorQuery->count(),
        ];

        if (! $apply) {
            return $summary;
        }

        $now = now();

        $libraryScanQuery->update([
            'status' => LibraryScanMediaTaskEnum::STATUS_CANCELED,
            'phase' => LibraryScanMediaTaskEnum::PHASE_CANCELED,
            'progress' => 100,
            'error_code' => 'streamable_video_cleared',
            'error_message' => 'Streamable video generation was cleared after browser-supported source policy update.',
            'updated_at' => $now,
        ]);

        $mediaProcessorQuery->update([
            'status' => MediaProcessorTaskStatus::FAILED,
            'phase' => 'canceled',
            'progress' => 100,
            'failed_at' => $now,
            'last_event_at' => $now,
            'error_code' => 'streamable_video_cleared',
            'error_message' => 'Streamable video generation was cleared after browser-supported source policy update.',
            'updated_at' => $now,
        ]);

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    private function deleteSupportedOutputs(
        BrowserVideoSupport $browserVideoSupport,
        MediaProbeService $probe,
        bool $apply,
    ): array {
        $chunk = max(1, (int) $this->option('chunk'));
        $summary = [
            'metadata_rows_scanned' => 0,
            'streamable_metadata_rows' => 0,
            'supported_originals' => 0,
            'deleted_outputs' => 0,
            'deleted_bytes' => 0,
            'missing_outputs' => 0,
            'metadata_updated' => 0,
            'skipped_unsupported_originals' => 0,
            'skipped_missing_originals' => 0,
            'skipped_unsafe_output_paths' => 0,
            'probe_failures' => 0,
        ];

        FileMetadata::query()
            ->with('file:id,path,mime_type')
            ->whereNotNull('payload')
            ->chunkById($chunk, function ($metadataRows) use ($browserVideoSupport, $probe, $apply, &$summary): void {
                foreach ($metadataRows as $metadata) {
                    $summary['metadata_rows_scanned']++;
                    $payload = is_array($metadata->payload) ? $metadata->payload : [];
                    $streamablePath = data_get($payload, 'conversions.streamable_video');

                    if (! is_string($streamablePath) || trim($streamablePath) === '') {
                        continue;
                    }

                    $summary['streamable_metadata_rows']++;
                    $streamablePath = $this->normalizeManagedStreamablePath($streamablePath);
                    if ($streamablePath === null) {
                        $summary['skipped_unsafe_output_paths']++;

                        continue;
                    }

                    $file = $metadata->file;
                    $resolved = AtlasPathResolver::resolveExistingPath($file?->path, [AtlasStorage::DISK]);
                    if (! $file || ! $resolved) {
                        $summary['skipped_missing_originals']++;

                        continue;
                    }

                    try {
                        $sourceProbe = $probe->probe($resolved['full_path']);
                    } catch (Throwable) {
                        $summary['probe_failures']++;

                        continue;
                    }

                    if (! $browserVideoSupport->isBrowserSupported($file->mime_type, $sourceProbe)) {
                        $summary['skipped_unsupported_originals']++;

                        continue;
                    }

                    $summary['supported_originals']++;
                    $this->deleteStreamableOutput($streamablePath, $apply, $summary);

                    if ($apply) {
                        $metadata->payload = $this->withoutStreamableVideo($payload);
                        $metadata->save();
                        $summary['metadata_updated']++;
                    }
                }
            });

        return $summary;
    }

    /**
     * @param  array<string, int>  $summary
     */
    private function deleteStreamableOutput(string $streamablePath, bool $apply, array &$summary): void
    {
        $disk = Storage::disk(AtlasStorage::DISK);

        if (! $disk->exists($streamablePath)) {
            $summary['missing_outputs']++;

            return;
        }

        try {
            $size = $disk->size($streamablePath);
            $summary['deleted_bytes'] += is_int($size) ? $size : 0;
        } catch (Throwable) {
            // Size is informational only.
        }

        if ($apply) {
            $disk->delete($streamablePath);
        }

        $summary['deleted_outputs']++;
    }

    private function normalizeManagedStreamablePath(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        if ($path === '' || str_contains($path, '..') || preg_match('/^[a-z]:/i', $path) === 1) {
            return null;
        }

        $namespace = explode('/', $path, 2)[0] ?? '';
        if (! in_array($namespace, AtlasStorage::namespaces(), true)) {
            return null;
        }

        if (! str_contains($path, '/conversions/') || ! str_ends_with(strtolower($path), '.mp4')) {
            return null;
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withoutStreamableVideo(array $payload): array
    {
        unset($payload['conversions']['streamable_video']);

        if (($payload['conversions'] ?? []) === []) {
            unset($payload['conversions']);
        }

        return $payload;
    }

    /**
     * @param  array<string, int>  $summary
     */
    private function printSummary(string $title, array $summary): void
    {
        $this->newLine();
        $this->info($title);
        $this->table(
            ['metric', 'count'],
            collect($summary)->map(fn (int $count, string $metric): array => [$metric, $count])->values()->all(),
        );
    }
}
