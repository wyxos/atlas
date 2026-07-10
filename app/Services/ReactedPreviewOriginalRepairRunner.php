<?php

namespace App\Services;

use App\Enums\DownloadTransferStatus;
use App\Enums\MediaProcessorTaskStatus;
use App\Jobs\GenerateFilePreviewAssets;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\MediaProcessorTask;
use App\Support\FileMimeType;
use App\Support\FilePreviewGeneration;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Throwable;

class ReactedPreviewOriginalRepairRunner
{
    private const string STALE_TASK_ERROR_CODE = 'preview_task_stale_superseded';

    /**
     * @var list<string>
     */
    private const array ACTIVE_DOWNLOAD_STATUSES = [
        DownloadTransferStatus::PENDING,
        DownloadTransferStatus::QUEUED,
        DownloadTransferStatus::PREPARING,
        DownloadTransferStatus::DOWNLOADING,
        DownloadTransferStatus::ASSEMBLING,
        DownloadTransferStatus::PREVIEWING,
        DownloadTransferStatus::PAUSED,
    ];

    public function __construct(
        private readonly FilePreviewOriginalHealthService $health,
        private readonly FilePreviewRepairService $repairs,
    ) {}

    /**
     * @param  array{report_records: int, downloads: list<int>, previews: list<int>}  $candidates
     * @param  array<string, int>  $stats
     * @return array{download: int, preview: int}
     */
    public function pump(
        array &$candidates,
        int $maxDownloads,
        int $maxPreviews,
        CarbonImmutable $cutoff,
        ReactedPreviewOriginalRepairState $state,
        array &$stats,
    ): array {
        $workloads = $this->workloads($cutoff);
        $downloadSlots = max(0, $maxDownloads - $workloads['download']);
        $downloadInflight = $this->repairOriginalCandidates(
            $candidates['downloads'],
            $downloadSlots,
            $cutoff,
            $state,
            $stats,
            $candidates['previews'],
        );

        $downloadReservation = max($workloads['download'], $downloadInflight);
        $previewSlots = max(
            0,
            $maxPreviews - $workloads['preview'] - min($maxPreviews, $downloadReservation),
        );
        $this->repairPreviewCandidates(
            $candidates['previews'],
            $previewSlots,
            $cutoff,
            $state,
            $stats,
            $candidates['downloads'],
        );

        return $workloads;
    }

    /**
     * @return array{download: int, preview: int}
     */
    public function workloads(CarbonImmutable $cutoff): array
    {
        return [
            'download' => $this->downloadWorkload(),
            'preview' => $this->previewWorkload($cutoff),
        ];
    }

    /**
     * @param  list<int>  $fileIds
     * @param  array<string, int>  $stats
     * @param  list<int>  $previewCandidates
     */
    private function repairOriginalCandidates(
        array &$fileIds,
        int $slots,
        CarbonImmutable $cutoff,
        ReactedPreviewOriginalRepairState $state,
        array &$stats,
        array &$previewCandidates,
    ): int {
        $inflight = 0;

        while ($slots > 0 && $fileIds !== []) {
            $fileId = array_shift($fileIds);
            $file = $this->findFile($fileId);
            if (! $file) {
                $stats['missing_records']++;
                $state->recordProcessed($fileId);

                continue;
            }
            if ($this->hasRequiredPreviewAssets($file)) {
                $stats['resolved']++;
                $state->recordProcessed($fileId);

                continue;
            }
            if ($this->isUnavailable($file)) {
                $stats['unavailable']++;
                $state->recordProcessed($fileId);

                continue;
            }
            if ($this->hasActiveDownload($fileId)) {
                $inflight++;
                $slots--;
                $state->recordProcessed($fileId, 'download');

                continue;
            }

            $originalState = $this->health->inspect($file);
            if ($originalState['healthy']) {
                $previewCandidates[] = $fileId;

                continue;
            }

            $slots--;
            try {
                $result = $this->repairs->repairUnhealthyOriginal($file, $originalState, null);
                $stats['stale_tasks_superseded'] += $this->supersedeStalePreviewTasks($fileId, $cutoff);

                if ($result['action'] === FilePreviewRepairService::ACTION_REDOWNLOAD_QUEUED) {
                    $stats['redownloads_queued']++;
                    $inflight++;
                } elseif ($result['action'] === FilePreviewRepairService::ACTION_UNAVAILABLE) {
                    $stats['unavailable']++;
                } else {
                    $stats['failed']++;
                }
                $state->recordProcessed($fileId, 'download');
            } catch (Throwable $exception) {
                throw new RuntimeException("Original repair failed for file ID {$fileId}.", previous: $exception);
            }
        }

        return $inflight;
    }

    /**
     * @param  list<int>  $fileIds
     * @param  array<string, int>  $stats
     * @param  list<int>  $downloadCandidates
     */
    private function repairPreviewCandidates(
        array &$fileIds,
        int $slots,
        CarbonImmutable $cutoff,
        ReactedPreviewOriginalRepairState $state,
        array &$stats,
        array &$downloadCandidates,
    ): void {
        while ($slots > 0 && $fileIds !== []) {
            $fileId = array_shift($fileIds);
            $file = $this->findFile($fileId);
            if (! $file) {
                $stats['missing_records']++;
                $state->recordProcessed($fileId);

                continue;
            }
            if ($this->hasRequiredPreviewAssets($file)) {
                $stats['resolved']++;
                $state->recordProcessed($fileId);

                continue;
            }
            if ($this->isUnavailable($file)) {
                $stats['unavailable']++;
                $state->recordProcessed($fileId);

                continue;
            }
            if ($this->hasActiveDownload($fileId)) {
                $state->recordProcessed($fileId, 'download');

                continue;
            }

            $originalState = $this->health->inspect($file);
            if (! $originalState['healthy']) {
                $downloadCandidates[] = $fileId;

                continue;
            }
            if ($this->hasFreshActivePreviewTask($fileId, $cutoff)) {
                $state->recordProcessed($fileId, 'preview');

                continue;
            }

            $slots--;
            try {
                $stats['stale_tasks_superseded'] += $this->supersedeStalePreviewTasks($fileId, $cutoff);
                GenerateFilePreviewAssets::dispatch($fileId, true)->onQueue('processing');
                $stats['previews_queued']++;
                $state->recordProcessed($fileId, 'preview');
            } catch (Throwable $exception) {
                throw new RuntimeException("Preview dispatch failed for file ID {$fileId}.", previous: $exception);
            }
        }
    }

    private function findFile(int $fileId): ?File
    {
        return File::query()
            ->with('latestPreviewMediaProcessorTask')
            ->find($fileId);
    }

    private function hasRequiredPreviewAssets(File $file): bool
    {
        $hasPreview = is_string($file->preview_path) && trim($file->preview_path) !== '';
        if (! FileMimeType::isVideo($file->mime_type)) {
            return $hasPreview;
        }

        return $hasPreview && is_string($file->poster_path) && trim($file->poster_path) !== '';
    }

    private function isUnavailable(File $file): bool
    {
        return (FilePreviewGeneration::state($file)['status'] ?? null) === FilePreviewGeneration::UNAVAILABLE_STATUS;
    }

    private function hasActiveDownload(int $fileId): bool
    {
        return DownloadTransfer::query()
            ->where('file_id', $fileId)
            ->whereIn('status', self::ACTIVE_DOWNLOAD_STATUSES)
            ->exists();
    }

    private function downloadWorkload(): int
    {
        $transfers = DownloadTransfer::query()
            ->whereIn('status', self::ACTIVE_DOWNLOAD_STATUSES)
            ->count();
        $queue = $this->queueSize($this->downloadQueueConnection(), 'downloads');

        return max($transfers, $queue);
    }

    private function previewWorkload(CarbonImmutable $cutoff): int
    {
        $tasks = $this->freshActiveTaskQuery($cutoff)->count();
        $queue = $this->queueSize((string) config('queue.default', 'sync'), 'processing');

        return max($tasks, $queue);
    }

    private function hasFreshActivePreviewTask(int $fileId, CarbonImmutable $cutoff): bool
    {
        return $this->freshActiveTaskQuery($cutoff)
            ->where('file_id', $fileId)
            ->whereIn('operation', FilePreviewGeneration::operations())
            ->exists();
    }

    private function freshActiveTaskQuery(CarbonImmutable $cutoff): Builder
    {
        return MediaProcessorTask::query()
            ->whereIn('status', MediaProcessorTaskStatus::active())
            ->where(function (Builder $query) use ($cutoff): void {
                foreach ($this->taskActivityColumns() as $column) {
                    $query->orWhere($column, '>', $cutoff);
                }
            });
    }

    private function supersedeStalePreviewTasks(int $fileId, CarbonImmutable $cutoff): int
    {
        $query = MediaProcessorTask::query()
            ->where('file_id', $fileId)
            ->whereIn('operation', FilePreviewGeneration::operations())
            ->whereIn('status', MediaProcessorTaskStatus::active());

        foreach ($this->taskActivityColumns() as $column) {
            $query->where(function (Builder $query) use ($column, $cutoff): void {
                $query->whereNull($column)
                    ->orWhere($column, '<=', $cutoff);
            });
        }

        return $query->update([
            'status' => MediaProcessorTaskStatus::FAILED,
            'phase' => 'stale_superseded',
            'progress' => 100,
            'failed_at' => now(),
            'last_event_at' => now(),
            'error_code' => self::STALE_TASK_ERROR_CODE,
            'error_message' => 'Stale preview task was superseded by a bounded repair run.',
            'updated_at' => now(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function taskActivityColumns(): array
    {
        return ['last_event_at', 'started_at', 'submitted_at', 'updated_at', 'created_at'];
    }

    private function queueSize(string $connection, string $queue): int
    {
        try {
            return max(0, (int) Queue::connection($connection)->size($queue));
        } catch (Throwable $exception) {
            throw new RuntimeException("Unable to inspect queue {$connection}:{$queue}.", previous: $exception);
        }
    }

    private function downloadQueueConnection(): string
    {
        $connection = trim((string) config('downloads.queue_connection', config('queue.default', 'database')));

        return $connection === '' || strtolower($connection) === 'sync' ? 'database' : $connection;
    }
}
