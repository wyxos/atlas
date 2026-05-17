<?php

namespace App\Services;

use App\Models\File;
use App\Models\Reaction;
use Illuminate\Support\Facades\DB;

class FileReactionAndStateMetricsService
{
    public function __construct(
        private readonly FileMetricClassifier $classifier,
    ) {}

    public function applyReactionChange(File $file, ?string $oldType, ?string $newType, bool $wasBlacklisted, bool $isBlacklisted, MetricsService $metrics): void
    {
        $types = array_values(array_unique(array_filter([$oldType, $newType])));

        $countsByType = [];
        if (! empty($types)) {
            $countsByType = Reaction::query()
                ->where('file_id', $file->id)
                ->whereIn('type', $types)
                ->select('type', DB::raw('COUNT(*) as total'))
                ->groupBy('type')
                ->pluck('total', 'type')
                ->map(fn ($value) => (int) $value)
                ->all();
        }

        $totalBefore = Reaction::where('file_id', $file->id)->count();
        $totalAfter = $totalBefore;

        $typeDelta = [];
        $loveDelta = 0;

        if ($oldType && $newType === null) {
            $totalAfter -= 1;
            $beforeCount = $countsByType[$oldType] ?? 0;
            $afterCount = $beforeCount - 1;
            if ($beforeCount > 0 && $afterCount === 0) {
                $typeDelta[$oldType] = -1;
                if ($oldType === 'love') {
                    $loveDelta = -1;
                }
            }
        } else {
            if ($oldType) {
                $beforeCount = $countsByType[$oldType] ?? 0;
                $afterCount = $beforeCount - 1;
                if ($beforeCount > 0 && $afterCount === 0) {
                    $typeDelta[$oldType] = -1;
                    if ($oldType === 'love') {
                        $loveDelta = -1;
                    }
                }
                $totalAfter -= 1;
            }

            if ($newType) {
                $beforeCount = $countsByType[$newType] ?? 0;
                $afterCount = $beforeCount + 1;
                if ($beforeCount === 0 && $afterCount > 0) {
                    $typeDelta[$newType] = ($typeDelta[$newType] ?? 0) + 1;
                    if ($newType === 'love') {
                        $loveDelta += 1;
                    }
                }
                $totalAfter += 1;
            }
        }

        foreach ($typeDelta as $type => $delta) {
            $key = match ($type) {
                'love' => MetricsService::KEY_REACTIONS_LOVE,
                'like' => MetricsService::KEY_REACTIONS_LIKE,
                'funny' => MetricsService::KEY_REACTIONS_FUNNY,
                default => null,
            };
            if ($key) {
                $metrics->incrementMetric($key, $delta);
            }
        }

        $isNotFound = $this->classifier->isNotFound($file);
        $beforeCounted = $this->classifier->countsAsUnreactedBacklog($wasBlacklisted, $isNotFound, $totalBefore);
        $afterCounted = $this->classifier->countsAsUnreactedBacklog($isBlacklisted, $isNotFound, $totalAfter);
        if ($beforeCounted !== $afterCounted) {
            $this->incrementUnreactedNotBlacklistedForFile($file, $afterCounted ? 1 : -1, $metrics);
        }

        $beforeReacted = $totalBefore > 0;
        $afterReacted = $totalAfter > 0;
        if ($beforeReacted !== $afterReacted) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_REACTED, $afterReacted ? 1 : -1);
        }

        $beforeReactedNotBlacklisted = $beforeReacted && ! $wasBlacklisted;
        $afterBlacklistedForReactionState = $newType === null ? $wasBlacklisted : $isBlacklisted;
        $afterReactedNotBlacklisted = $afterReacted && ! $afterBlacklistedForReactionState;
        if ($beforeReactedNotBlacklisted !== $afterReactedNotBlacklisted) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_REACTED_NOT_BLACKLISTED, $afterReactedNotBlacklisted ? 1 : -1);
        }

        if ($loveDelta !== 0) {
            $metrics->incrementContainersForFileIds([$file->id], 'files_favorited', $loveDelta);
        }
    }

    /**
     * Apply preview metrics before preview_count is incremented.
     *
     * @param  array<int>  $fileIds
     */
    public function applyPreviewIncrement(array $fileIds, MetricsService $metrics): void
    {
        if (empty($fileIds)) {
            return;
        }

        $newlyPreviewed = DB::table('files')
            ->whereIn('id', $fileIds)
            ->whereNull('blacklisted_at')
            ->where('previewed_count', 0)
            ->count();

        if ($newlyPreviewed > 0) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_PREVIEWED_NOT_BLACKLISTED, $newlyPreviewed);
        }

        $newlyPreviewedUnreacted = DB::table('files')
            ->whereIn('id', $fileIds)
            ->whereNull('blacklisted_at')
            ->where('not_found', false)
            ->where('previewed_count', 0)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('reactions')
                    ->whereColumn('reactions.file_id', 'files.id');
            })
            ->count();

        if ($newlyPreviewedUnreacted > 0) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_UNREACTED_UNPREVIEWED_NOT_BLACKLISTED, -$newlyPreviewedUnreacted);
            $metrics->incrementMetric(MetricsService::KEY_FILES_UNREACTED_PREVIEWED_NOT_BLACKLISTED, $newlyPreviewedUnreacted);
        }
    }

    /**
     * Apply preview metrics before preview_count is reset to zero.
     *
     * @param  array<int>  $fileIds
     */
    public function applyPreviewReset(array $fileIds, MetricsService $metrics): void
    {
        if (empty($fileIds)) {
            return;
        }

        $resetPreviewed = DB::table('files')
            ->whereIn('id', $fileIds)
            ->whereNull('blacklisted_at')
            ->where('previewed_count', '>', 0)
            ->count();

        if ($resetPreviewed > 0) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_PREVIEWED_NOT_BLACKLISTED, -$resetPreviewed);
        }

        $resetUnreacted = DB::table('files')
            ->whereIn('id', $fileIds)
            ->whereNull('blacklisted_at')
            ->where('not_found', false)
            ->where('previewed_count', '>', 0)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('reactions')
                    ->whereColumn('reactions.file_id', 'files.id');
            })
            ->count();

        if ($resetUnreacted > 0) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_UNREACTED_PREVIEWED_NOT_BLACKLISTED, -$resetUnreacted);
            $metrics->incrementMetric(MetricsService::KEY_FILES_UNREACTED_UNPREVIEWED_NOT_BLACKLISTED, $resetUnreacted);
        }
    }

    public function applyDownload(File $file, bool $wasDownloaded, bool $hadPath, bool $wasBlacklisted, MetricsService $metrics): void
    {
        if (! $wasDownloaded) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_DOWNLOADED, 1);
            $metrics->incrementContainersForFileIds([$file->id], 'files_downloaded', 1);
        }

        if (! $hadPath && $this->classifier->hasPath($file)) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_WITH_PATH, 1);
        }

        if (! $wasBlacklisted && $file->blacklisted_at === null && $this->classifier->hasPath($file)) {
            if (! $hadPath) {
                $this->incrementActiveStoredMetrics($file, 1, $metrics);
            } elseif (! $wasDownloaded && (bool) $file->downloaded) {
                $metrics->incrementMetric(MetricsService::KEY_FILES_DOWNLOADED_WITH_PATH_NOT_BLACKLISTED, 1);
            }
        }
    }

    /**
     * Apply metrics for clearing download state on a file.
     */
    public function applyDownloadClear(File $file, bool $wasDownloaded, MetricsService $metrics): void
    {
        if ($wasDownloaded) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_DOWNLOADED, -1);
            $metrics->incrementContainersForFileIds([$file->id], 'files_downloaded', -1);
        }

        if ($this->classifier->hasPath($file)) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_WITH_PATH, -1);
        }

        if ($file->blacklisted_at === null && $this->classifier->hasPath($file)) {
            $this->incrementActiveStoredMetrics($file, -1, $metrics);
        }
    }

    public function applyNotFoundMark(File $file, bool $wasNotFound, MetricsService $metrics): void
    {
        if ($wasNotFound) {
            return;
        }

        $metrics->incrementMetric(MetricsService::KEY_FILES_NOT_FOUND, 1);
        if ($file->blacklisted_at === null && ! $this->classifier->hasPath($file)) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_NOT_FOUND_RECORDS_ONLY_NOT_BLACKLISTED, 1);
        }
        $this->incrementAvailableSourceMetric($file, -1, $metrics);
        $this->incrementUnreactedForNotFoundTransition($file, -1, $metrics);
    }

    public function applyNotFoundClear(File $file, bool $wasNotFound, MetricsService $metrics): void
    {
        if (! $wasNotFound) {
            return;
        }

        $metrics->incrementMetric(MetricsService::KEY_FILES_NOT_FOUND, -1);
        if ($file->blacklisted_at === null && ! $this->classifier->hasPath($file)) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_NOT_FOUND_RECORDS_ONLY_NOT_BLACKLISTED, -1);
        }
        $this->incrementAvailableSourceMetric($file, 1, $metrics);
        $this->incrementUnreactedForNotFoundTransition($file, 1, $metrics);
    }

    private function incrementUnreactedNotBlacklistedForFile(File $file, int $delta, MetricsService $metrics): void
    {
        if ($delta === 0) {
            return;
        }

        $metrics->incrementMetric(MetricsService::KEY_FILES_UNREACTED_NOT_BLACKLISTED, $delta);
        $metrics->incrementMetric($this->classifier->unreactedPreviewMetricKey($file), $delta);
    }

    /**
     * @param  iterable<int, object>  $rows
     */
    private function incrementUnreactedPreviewBuckets(iterable $rows, int $direction, MetricsService $metrics): void
    {
        if ($direction === 0) {
            return;
        }

        $previewed = 0;
        $unpreviewed = 0;

        foreach ($rows as $row) {
            if ((int) ($row->previewed_count ?? 0) > 0) {
                $previewed++;
            } else {
                $unpreviewed++;
            }
        }

        $total = $previewed + $unpreviewed;
        if ($total === 0) {
            return;
        }

        $metrics->incrementMetric(MetricsService::KEY_FILES_UNREACTED_NOT_BLACKLISTED, $total * $direction);
        if ($previewed > 0) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_UNREACTED_PREVIEWED_NOT_BLACKLISTED, $previewed * $direction);
        }
        if ($unpreviewed > 0) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_UNREACTED_UNPREVIEWED_NOT_BLACKLISTED, $unpreviewed * $direction);
        }
    }

    private function incrementUnreactedForNotFoundTransition(File $file, int $delta, MetricsService $metrics): void
    {
        if ($file->blacklisted_at !== null || Reaction::where('file_id', $file->id)->exists()) {
            return;
        }

        $this->incrementUnreactedNotBlacklistedForFile($file, $delta, $metrics);
    }

    private function incrementActiveInventoryMetrics(File $file, int $delta, MetricsService $metrics): void
    {
        if ($this->classifier->hasPath($file)) {
            $this->incrementActiveStoredMetrics($file, $delta, $metrics);

            return;
        }

        if ($this->classifier->isNotFound($file)) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_NOT_FOUND_RECORDS_ONLY_NOT_BLACKLISTED, $delta);
        }
    }

    private function incrementActiveStoredMetrics(File $file, int $delta, MetricsService $metrics): void
    {
        $metrics->incrementMetric(MetricsService::KEY_FILES_WITH_PATH_NOT_BLACKLISTED, $delta);
        $metrics->incrementMetric($this->classifier->storedFileTypeMetricKey($file), $delta);

        if ((bool) $file->downloaded) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_DOWNLOADED_WITH_PATH_NOT_BLACKLISTED, $delta);
        }
    }

    private function incrementAvailableSourceMetric(File $file, int $delta, MetricsService $metrics): void
    {
        $metrics->incrementMetric(
            $this->classifier->isLocalSource($file) ? MetricsService::KEY_FILES_LOCAL_AVAILABLE : MetricsService::KEY_FILES_NON_LOCAL_AVAILABLE,
            $delta,
        );
    }
}
