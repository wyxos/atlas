<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Support\Facades\DB;

class MetricsService
{
    private const int CONTAINER_UPDATE_BATCH_SIZE = 1000;

    public const string KEY_FILES_TOTAL = 'files_total';

    public const string KEY_FILES_DOWNLOADED = 'files_downloaded';

    public const string KEY_FILES_WITH_PATH = 'files_with_path';

    public const string KEY_FILES_WITH_PATH_NOT_BLACKLISTED = 'files_with_path_not_blacklisted';

    public const string KEY_FILES_DOWNLOADED_WITH_PATH_NOT_BLACKLISTED = 'files_downloaded_with_path_not_blacklisted';

    public const string KEY_FILES_LOCAL = 'files_local';

    public const string KEY_FILES_LOCAL_AVAILABLE = 'files_local_available';

    public const string KEY_FILES_NON_LOCAL_AVAILABLE = 'files_non_local_available';

    public const string KEY_FILES_NOT_FOUND = 'files_not_found';

    public const string KEY_FILES_NOT_FOUND_RECORDS_ONLY_NOT_BLACKLISTED = 'files_not_found_records_only_not_blacklisted';

    public const string KEY_FILES_TYPE_IMAGE = 'files_type_image';

    public const string KEY_FILES_TYPE_IMAGE_WITH_PATH_NOT_BLACKLISTED = 'files_type_image_with_path_not_blacklisted';

    public const string KEY_FILES_TYPE_VIDEO = 'files_type_video';

    public const string KEY_FILES_TYPE_VIDEO_WITH_PATH_NOT_BLACKLISTED = 'files_type_video_with_path_not_blacklisted';

    public const string KEY_FILES_TYPE_AUDIO = 'files_type_audio';

    public const string KEY_FILES_TYPE_AUDIO_WITH_PATH_NOT_BLACKLISTED = 'files_type_audio_with_path_not_blacklisted';

    public const string KEY_FILES_TYPE_OTHER = 'files_type_other';

    public const string KEY_FILES_TYPE_OTHER_WITH_PATH_NOT_BLACKLISTED = 'files_type_other_with_path_not_blacklisted';

    public const string KEY_FILES_REACTED = 'files_reacted';

    public const string KEY_FILES_REACTED_NOT_BLACKLISTED = 'files_reacted_not_blacklisted';

    public const string KEY_FILES_PREVIEWED_NOT_BLACKLISTED = 'files_previewed_not_blacklisted';

    public const string KEY_FILES_BLACKLISTED_TOTAL = 'files_blacklisted_total';

    public const string KEY_FILES_BLACKLISTED_MANUAL = 'files_blacklisted_manual';

    public const string KEY_FILES_BLACKLISTED_FEED_REMOVED = 'files_blacklisted_feed_removed';

    public const string KEY_FILES_BLACKLISTED_MANUAL_IN_FEED = 'files_blacklisted_manual_in_feed';

    public const string KEY_FILES_BLACKLISTED_AUTO_IN_FEED = 'files_blacklisted_auto_in_feed';

    public const string KEY_FILES_AUTO_BLACKLISTED = 'files_auto_blacklisted';

    public const string KEY_FILES_UNREACTED_NOT_BLACKLISTED = 'files_unreacted_not_blacklisted';

    public const string KEY_FILES_UNREACTED_PREVIEWED_NOT_BLACKLISTED = 'files_unreacted_previewed_not_blacklisted';

    public const string KEY_FILES_UNREACTED_UNPREVIEWED_NOT_BLACKLISTED = 'files_unreacted_unpreviewed_not_blacklisted';

    public const string KEY_REACTIONS_LOVE = 'reactions_love';

    public const string KEY_REACTIONS_LIKE = 'reactions_like';

    public const string KEY_REACTIONS_FUNNY = 'reactions_funny';

    public const string KEY_CONTAINERS_TOTAL = 'containers_total';

    public const string KEY_CONTAINERS_BLACKLISTED = 'containers_blacklisted';

    /**
     * Increment a metric by a delta (can be negative).
     */
    public function incrementMetric(string $key, int $delta, ?string $description = null): void
    {
        if ($delta === 0) {
            return;
        }

        DB::transaction(function () use ($key, $delta, $description) {
            $valueExpression = $delta < 0
                ? 'CASE WHEN value < '.abs($delta).' THEN 0 ELSE value - '.abs($delta).' END'
                : "value + {$delta}";
            $updates = [
                'value' => DB::raw($valueExpression),
                'updated_at' => now(),
            ];

            if ($description !== null) {
                $updates['description'] = $description;
            }

            $affected = DB::table('metrics')
                ->where('key', $key)
                ->update($updates);

            if ($affected === 0) {
                DB::table('metrics')->insert([
                    'key' => $key,
                    'description' => $description,
                    'value' => max(0, $delta),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /**
     * Set a metric to an explicit value.
     */
    public function setMetric(string $key, int $value, ?string $description = null): void
    {
        $payload = [
            'key' => $key,
            'value' => max(0, $value),
            'updated_at' => now(),
        ];

        if ($description !== null) {
            $payload['description'] = $description;
        }

        $exists = DB::table('metrics')->where('key', $key)->exists();
        if ($exists) {
            DB::table('metrics')->where('key', $key)->update($payload);
        } else {
            DB::table('metrics')->insert($payload + ['created_at' => now()]);
        }
    }

    /**
     * Get metric values keyed by metric key (missing keys return 0).
     *
     * @param  array<int, string>  $keys
     * @return array<string, int>
     */
    public function getMetrics(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        $values = DB::table('metrics')
            ->whereIn('key', $keys)
            ->pluck('value', 'key')
            ->map(fn ($value) => (int) $value)
            ->all();

        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $values[$key] ?? 0;
        }

        return $result;
    }

    /**
     * Increment container counters by container id counts.
     *
     * @param  array<int, int>  $countsByContainer
     */
    public function incrementContainersByCounts(string $column, array $countsByContainer): void
    {
        if (empty($countsByContainer)) {
            return;
        }
        $grammar = DB::query()->getGrammar();
        $wrappedId = $grammar->wrap('id');
        $wrappedColumn = $grammar->wrap($column);

        foreach (array_chunk($countsByContainer, self::CONTAINER_UPDATE_BATCH_SIZE, true) as $batch) {
            $cases = [];
            $containerIds = [];

            foreach ($batch as $containerId => $count) {
                $count = (int) $count;
                if ($count === 0) {
                    continue;
                }

                $containerId = (int) $containerId;
                $containerIds[] = $containerId;
                $cases[] = "WHEN {$containerId} THEN {$count}";
            }

            if ($cases === []) {
                continue;
            }

            $deltaCaseExpression = 'CASE '.$wrappedId.' '.implode(' ', $cases).' ELSE 0 END';
            $updateExpression = "CASE WHEN {$wrappedColumn} + ({$deltaCaseExpression}) < 0 THEN 0 ELSE {$wrappedColumn} + ({$deltaCaseExpression}) END";

            DB::table('containers')
                ->whereIn('id', $containerIds)
                ->update([
                    $column => DB::raw($updateExpression),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Increment a container counter for all containers attached to the given files.
     *
     * @param  array<int>  $fileIds
     */
    public function incrementContainersForFileIds(array $fileIds, string $column, int $delta): void
    {
        if (empty($fileIds) || $delta === 0) {
            return;
        }

        $counts = DB::table('container_file')
            ->select('container_id', DB::raw('COUNT(*) as total'))
            ->whereIn('file_id', $fileIds)
            ->groupBy('container_id')
            ->pluck('total', 'container_id')
            ->map(fn ($value) => (int) $value)
            ->all();

        $increments = [];
        foreach ($counts as $containerId => $count) {
            $increments[$containerId] = $count * $delta;
        }

        $this->incrementContainersByCounts($column, $increments);
    }

    /**
     * Apply reaction change metrics for a single file (before reaction is written).
     */
    public function applyReactionChange(File $file, ?string $oldType, ?string $newType, bool $wasBlacklisted, bool $isBlacklisted): void
    {
        app(FileReactionAndStateMetricsService::class)->applyReactionChange($file, $oldType, $newType, $wasBlacklisted, $isBlacklisted, $this);
    }

    public function applyAutoBlacklistAdd(array $fileIds): void
    {
        app(FileBlacklistMetricsService::class)->applyAutoBlacklistAdd($fileIds, $this);
    }

    public function applyAutoBlacklistClear(File $file, bool $countAsManualBlacklisted = true): void
    {
        app(FileBlacklistMetricsService::class)->applyAutoBlacklistClear($file, $countAsManualBlacklisted, $this);
    }

    public function applyBlacklistAdd(array $fileIds, bool $autoBlacklisted = false, ?int $minimumPreviewedCount = null): void
    {
        app(FileBlacklistMetricsService::class)->applyBlacklistAdd($fileIds, $autoBlacklisted, $minimumPreviewedCount, $this);
    }

    public function applyBlacklistClear(File $file, bool $adjustUnreacted = true, ?bool $wasAutoBlacklisted = null, ?bool $hadTerminalPreviewCount = null): void
    {
        app(FileBlacklistMetricsService::class)->applyBlacklistClear($file, $adjustUnreacted, $wasAutoBlacklisted, $hadTerminalPreviewCount, $this);
    }

    public function applyPreviewIncrement(array $fileIds): void
    {
        app(FileReactionAndStateMetricsService::class)->applyPreviewIncrement($fileIds, $this);
    }

    public function applyPreviewReset(array $fileIds): void
    {
        app(FileReactionAndStateMetricsService::class)->applyPreviewReset($fileIds, $this);
    }

    public function applyBlacklistedFeedRemovedMark(array $fileIds): void
    {
        app(FileBlacklistMetricsService::class)->applyBlacklistedFeedRemovedMark($fileIds, $this);
    }

    public function applyBlacklistedFeedRemovedClear(array $fileIds): void
    {
        app(FileBlacklistMetricsService::class)->applyBlacklistedFeedRemovedClear($fileIds, $this);
    }

    public function applyDownload(File $file, bool $wasDownloaded, bool $hadPath = false, bool $wasBlacklisted = false): void
    {
        app(FileReactionAndStateMetricsService::class)->applyDownload($file, $wasDownloaded, $hadPath, $wasBlacklisted, $this);
    }

    public function applyDownloadClear(File $file, bool $wasDownloaded): void
    {
        app(FileReactionAndStateMetricsService::class)->applyDownloadClear($file, $wasDownloaded, $this);
    }

    public function applyNotFoundMark(File $file, bool $wasNotFound): void
    {
        app(FileReactionAndStateMetricsService::class)->applyNotFoundMark($file, $wasNotFound, $this);
    }

    public function applyNotFoundClear(File $file, bool $wasNotFound): void
    {
        app(FileReactionAndStateMetricsService::class)->applyNotFoundClear($file, $wasNotFound, $this);
    }

    /**
     * Recompute all metrics and container counters from the database.
     */
    public function syncAll(): void
    {
        app(FileMetricsSyncService::class)->sync($this);
        app(ContainerMetricsSyncService::class)->sync($this);
    }
}
