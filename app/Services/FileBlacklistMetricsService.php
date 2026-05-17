<?php

namespace App\Services;

use App\Models\File;
use App\Models\Reaction;
use Illuminate\Support\Facades\DB;

class FileBlacklistMetricsService
{
    public function __construct(
        private readonly FileMetricClassifier $classifier,
    ) {}

    public function applyAutoBlacklistAdd(array $fileIds, MetricsService $metrics): void
    {
        if (empty($fileIds)) {
            return;
        }

        $eligibleFiles = File::query()
            ->whereIn('id', $fileIds)
            ->where('auto_blacklisted', false)
            ->get(['id', 'blacklisted_at', 'previewed_count']);

        if ($eligibleFiles->isEmpty()) {
            return;
        }

        $metrics->incrementMetric(MetricsService::KEY_FILES_AUTO_BLACKLISTED, $eligibleFiles->count());

        $manualBlacklisted = $eligibleFiles
            ->filter(fn (File $file): bool => $file->blacklisted_at !== null)
            ->count();
        if ($manualBlacklisted > 0) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_BLACKLISTED_MANUAL, -$manualBlacklisted);
        }

        $manualInFeedBlacklisted = $eligibleFiles
            ->filter(fn (File $file): bool => $file->blacklisted_at !== null && ! $this->classifier->isFeedRemoved($file))
            ->count();
        if ($manualInFeedBlacklisted > 0) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_BLACKLISTED_MANUAL_IN_FEED, -$manualInFeedBlacklisted);
            $metrics->incrementMetric(MetricsService::KEY_FILES_BLACKLISTED_AUTO_IN_FEED, $manualInFeedBlacklisted);
        }
    }

    public function applyAutoBlacklistClear(File $file, bool $countAsManualBlacklisted, MetricsService $metrics): void
    {
        if (! (bool) $file->auto_blacklisted) {
            return;
        }

        $metrics->incrementMetric(MetricsService::KEY_FILES_AUTO_BLACKLISTED, -1);

        if ($countAsManualBlacklisted && $file->blacklisted_at !== null) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_BLACKLISTED_MANUAL, 1);

            if (! $this->classifier->isFeedRemoved($file)) {
                $metrics->incrementMetric(MetricsService::KEY_FILES_BLACKLISTED_AUTO_IN_FEED, -1);
                $metrics->incrementMetric(MetricsService::KEY_FILES_BLACKLISTED_MANUAL_IN_FEED, 1);
            }
        }
    }

    /**
     * Apply metrics for bulk blacklisting (before updating files).
     *
     * @param  array<int>  $fileIds
     */
    public function applyBlacklistAdd(
        array $fileIds,
        bool $autoBlacklisted,
        ?int $minimumPreviewedCount,
        MetricsService $metrics,
    ): void {
        if (empty($fileIds)) {
            return;
        }

        $eligibleFiles = File::query()
            ->whereIn('id', $fileIds)
            ->whereNull('blacklisted_at')
            ->get(['id', 'downloaded', 'mime_type', 'not_found', 'path', 'previewed_count']);

        if ($eligibleFiles->isEmpty()) {
            return;
        }

        $eligibleIds = $eligibleFiles
            ->pluck('id')
            ->map(fn ($value) => (int) $value)
            ->all();

        $metrics->incrementMetric(MetricsService::KEY_FILES_BLACKLISTED_TOTAL, count($eligibleIds));

        if (! $autoBlacklisted) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_BLACKLISTED_MANUAL, count($eligibleIds));
        }

        $feedRemovedCount = $eligibleFiles
            ->filter(fn (File $file): bool => $this->classifier->willBeFeedRemoved($file, $minimumPreviewedCount))
            ->count();
        if ($feedRemovedCount > 0) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_BLACKLISTED_FEED_REMOVED, $feedRemovedCount);
        }

        $inFeedCount = $eligibleFiles->count() - $feedRemovedCount;
        if ($inFeedCount > 0) {
            $metrics->incrementMetric(
                $autoBlacklisted ? MetricsService::KEY_FILES_BLACKLISTED_AUTO_IN_FEED : MetricsService::KEY_FILES_BLACKLISTED_MANUAL_IN_FEED,
                $inFeedCount,
            );
        }

        $previewedCount = $eligibleFiles
            ->filter(fn (File $file): bool => (int) $file->previewed_count > 0)
            ->count();
        if ($previewedCount > 0) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_PREVIEWED_NOT_BLACKLISTED, -$previewedCount);
        }

        foreach ($eligibleFiles as $file) {
            $this->incrementActiveInventoryMetrics($file, -1, $metrics);
        }

        $filesWithoutReactions = DB::table('files')
            ->select(['id', 'previewed_count'])
            ->whereIn('id', $eligibleIds)
            ->where('not_found', false)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('reactions')
                    ->whereColumn('reactions.file_id', 'files.id');
            })
            ->get();

        $this->incrementUnreactedPreviewBuckets($filesWithoutReactions, -1, $metrics);

        $metrics->incrementContainersForFileIds($eligibleIds, 'files_blacklisted', 1);
    }

    /**
     * Apply metrics for clearing a blacklist on a single file.
     */
    public function applyBlacklistClear(
        File $file,
        bool $adjustUnreacted,
        ?bool $wasAutoBlacklisted,
        ?bool $hadTerminalPreviewCount,
        MetricsService $metrics,
    ): void {
        $metrics->incrementMetric(MetricsService::KEY_FILES_BLACKLISTED_TOTAL, -1);

        $wasAutoBlacklisted ??= (bool) $file->auto_blacklisted;
        $hadTerminalPreviewCount ??= (int) $file->previewed_count >= FilePreviewService::FEED_REMOVED_PREVIEW_COUNT;

        if (! $wasAutoBlacklisted) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_BLACKLISTED_MANUAL, -1);
        }

        if ($hadTerminalPreviewCount) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_BLACKLISTED_FEED_REMOVED, -1);
        } else {
            $metrics->incrementMetric(
                $wasAutoBlacklisted ? MetricsService::KEY_FILES_BLACKLISTED_AUTO_IN_FEED : MetricsService::KEY_FILES_BLACKLISTED_MANUAL_IN_FEED,
                -1,
            );
        }

        $hasReactions = Reaction::where('file_id', $file->id)->exists();
        if ($adjustUnreacted && $hasReactions) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_REACTED_NOT_BLACKLISTED, 1);
        }

        if ((int) $file->previewed_count > 0) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_PREVIEWED_NOT_BLACKLISTED, 1);
        }

        if ($adjustUnreacted) {
            if (! $hasReactions && ! $this->classifier->isNotFound($file)) {
                $this->incrementUnreactedNotBlacklistedForFile($file, 1, $metrics);
            }
        }

        $this->incrementActiveInventoryMetrics($file, 1, $metrics);

        $metrics->incrementContainersForFileIds([$file->id], 'files_blacklisted', -1);
    }

    /**
     * Apply metrics before blacklisted files are forced to the feed-removed preview count.
     *
     * @param  array<int>  $fileIds
     */
    public function applyBlacklistedFeedRemovedMark(array $fileIds, MetricsService $metrics): void
    {
        if (empty($fileIds)) {
            return;
        }

        $count = File::query()
            ->whereIn('id', $fileIds)
            ->whereNotNull('blacklisted_at')
            ->where('previewed_count', '<', FilePreviewService::FEED_REMOVED_PREVIEW_COUNT)
            ->count();

        if ($count > 0) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_BLACKLISTED_FEED_REMOVED, $count);
            $this->incrementBlacklistedInFeedByAutoState($fileIds, -1, terminal: false, metrics: $metrics);
        }
    }

    /**
     * Apply metrics before blacklisted files are moved below the feed-removed preview count.
     *
     * @param  array<int>  $fileIds
     */
    public function applyBlacklistedFeedRemovedClear(array $fileIds, MetricsService $metrics): void
    {
        if (empty($fileIds)) {
            return;
        }

        $count = File::query()
            ->whereIn('id', $fileIds)
            ->whereNotNull('blacklisted_at')
            ->where('previewed_count', '>=', FilePreviewService::FEED_REMOVED_PREVIEW_COUNT)
            ->count();

        if ($count > 0) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_BLACKLISTED_FEED_REMOVED, -$count);
            $this->incrementBlacklistedInFeedByAutoState($fileIds, 1, terminal: true, metrics: $metrics);
        }
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

    private function incrementBlacklistedInFeedByAutoState(array $fileIds, int $direction, bool $terminal, MetricsService $metrics): void
    {
        if ($direction === 0 || $fileIds === []) {
            return;
        }

        $operator = $terminal ? '>=' : '<';
        $counts = File::query()
            ->whereIn('id', $fileIds)
            ->whereNotNull('blacklisted_at')
            ->where('previewed_count', $operator, FilePreviewService::FEED_REMOVED_PREVIEW_COUNT)
            ->selectRaw('SUM(CASE WHEN auto_blacklisted = 1 THEN 1 ELSE 0 END) as auto_total')
            ->selectRaw('SUM(CASE WHEN auto_blacklisted = 0 THEN 1 ELSE 0 END) as manual_total')
            ->first();

        $manual = (int) ($counts->manual_total ?? 0);
        if ($manual > 0) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_BLACKLISTED_MANUAL_IN_FEED, $manual * $direction);
        }

        $auto = (int) ($counts->auto_total ?? 0);
        if ($auto > 0) {
            $metrics->incrementMetric(MetricsService::KEY_FILES_BLACKLISTED_AUTO_IN_FEED, $auto * $direction);
        }
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
}
