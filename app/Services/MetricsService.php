<?php

namespace App\Services;

use App\Models\File;
use App\Models\Reaction;
use Illuminate\Support\Facades\DB;

class MetricsService
{
    private const int CONTAINER_UPDATE_BATCH_SIZE = 1000;

    public const string KEY_FILES_TOTAL = 'files_total';

    public const string KEY_FILES_DOWNLOADED = 'files_downloaded';

    public const string KEY_FILES_LOCAL = 'files_local';

    public const string KEY_FILES_NOT_FOUND = 'files_not_found';

    public const string KEY_FILES_BLACKLISTED_TOTAL = 'files_blacklisted_total';

    public const string KEY_FILES_BLACKLISTED_MANUAL = 'files_blacklisted_manual';

    public const string KEY_FILES_BLACKLISTED_FEED_REMOVED = 'files_blacklisted_feed_removed';

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
                'love' => self::KEY_REACTIONS_LOVE,
                'like' => self::KEY_REACTIONS_LIKE,
                'funny' => self::KEY_REACTIONS_FUNNY,
                default => null,
            };
            if ($key) {
                $this->incrementMetric($key, $delta);
            }
        }

        $beforeCounted = ! $wasBlacklisted && $totalBefore === 0;
        $afterCounted = ! $isBlacklisted && $totalAfter === 0;
        if ($beforeCounted !== $afterCounted) {
            $this->incrementUnreactedNotBlacklistedForFile($file, $afterCounted ? 1 : -1);
        }

        if ($loveDelta !== 0) {
            $this->incrementContainersForFileIds([$file->id], 'files_favorited', $loveDelta);
        }
    }

    /**
     * Apply metrics for bulk auto-blacklist marking (before updating files).
     *
     * @param  array<int>  $fileIds
     */
    public function applyAutoBlacklistAdd(array $fileIds): void
    {
        if (empty($fileIds)) {
            return;
        }

        $eligibleFiles = File::query()
            ->whereIn('id', $fileIds)
            ->where('auto_blacklisted', false)
            ->get(['id', 'blacklisted_at']);

        if ($eligibleFiles->isEmpty()) {
            return;
        }

        $this->incrementMetric(self::KEY_FILES_AUTO_BLACKLISTED, $eligibleFiles->count());

        $manualBlacklisted = $eligibleFiles
            ->filter(fn (File $file): bool => $file->blacklisted_at !== null)
            ->count();
        if ($manualBlacklisted > 0) {
            $this->incrementMetric(self::KEY_FILES_BLACKLISTED_MANUAL, -$manualBlacklisted);
        }
    }

    public function applyAutoBlacklistClear(File $file, bool $countAsManualBlacklisted = true): void
    {
        if (! (bool) $file->auto_blacklisted) {
            return;
        }

        $this->incrementMetric(self::KEY_FILES_AUTO_BLACKLISTED, -1);

        if ($countAsManualBlacklisted && $file->blacklisted_at !== null) {
            $this->incrementMetric(self::KEY_FILES_BLACKLISTED_MANUAL, 1);
        }
    }

    /**
     * Apply metrics for bulk blacklisting (before updating files).
     *
     * @param  array<int>  $fileIds
     */
    public function applyBlacklistAdd(
        array $fileIds,
        bool $autoBlacklisted = false,
        ?int $minimumPreviewedCount = null
    ): void {
        if (empty($fileIds)) {
            return;
        }

        $eligibleFiles = File::query()
            ->whereIn('id', $fileIds)
            ->whereNull('blacklisted_at')
            ->get(['id', 'previewed_count']);

        if ($eligibleFiles->isEmpty()) {
            return;
        }

        $eligibleIds = $eligibleFiles
            ->pluck('id')
            ->map(fn ($value) => (int) $value)
            ->all();

        $this->incrementMetric(self::KEY_FILES_BLACKLISTED_TOTAL, count($eligibleIds));

        if (! $autoBlacklisted) {
            $this->incrementMetric(self::KEY_FILES_BLACKLISTED_MANUAL, count($eligibleIds));
        }

        $feedRemovedCount = $eligibleFiles
            ->filter(fn (File $file): bool => $this->willBeFeedRemoved($file, $minimumPreviewedCount))
            ->count();
        if ($feedRemovedCount > 0) {
            $this->incrementMetric(self::KEY_FILES_BLACKLISTED_FEED_REMOVED, $feedRemovedCount);
        }

        $filesWithoutReactions = DB::table('files')
            ->select(['id', 'previewed_count'])
            ->whereIn('id', $eligibleIds)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('reactions')
                    ->whereColumn('reactions.file_id', 'files.id');
            })
            ->get();

        $this->incrementUnreactedPreviewBuckets($filesWithoutReactions, -1);

        $this->incrementContainersForFileIds($eligibleIds, 'files_blacklisted', 1);
    }

    /**
     * Apply metrics for clearing a blacklist on a single file.
     */
    public function applyBlacklistClear(
        File $file,
        bool $adjustUnreacted = true,
        ?bool $wasAutoBlacklisted = null,
        ?bool $hadTerminalPreviewCount = null,
    ): void {
        $this->incrementMetric(self::KEY_FILES_BLACKLISTED_TOTAL, -1);

        $wasAutoBlacklisted ??= (bool) $file->auto_blacklisted;
        $hadTerminalPreviewCount ??= (int) $file->previewed_count >= FilePreviewService::FEED_REMOVED_PREVIEW_COUNT;

        if (! $wasAutoBlacklisted) {
            $this->incrementMetric(self::KEY_FILES_BLACKLISTED_MANUAL, -1);
        }

        if ($hadTerminalPreviewCount) {
            $this->incrementMetric(self::KEY_FILES_BLACKLISTED_FEED_REMOVED, -1);
        }

        if ($adjustUnreacted) {
            $hasReactions = Reaction::where('file_id', $file->id)->exists();
            if (! $hasReactions) {
                $this->incrementUnreactedNotBlacklistedForFile($file, 1);
            }
        }

        $this->incrementContainersForFileIds([$file->id], 'files_blacklisted', -1);
    }

    /**
     * Apply preview metrics before preview_count is incremented.
     *
     * @param  array<int>  $fileIds
     */
    public function applyPreviewIncrement(array $fileIds): void
    {
        if (empty($fileIds)) {
            return;
        }

        $newlyPreviewedUnreacted = DB::table('files')
            ->whereIn('id', $fileIds)
            ->whereNull('blacklisted_at')
            ->where('previewed_count', 0)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('reactions')
                    ->whereColumn('reactions.file_id', 'files.id');
            })
            ->count();

        if ($newlyPreviewedUnreacted > 0) {
            $this->incrementMetric(self::KEY_FILES_UNREACTED_UNPREVIEWED_NOT_BLACKLISTED, -$newlyPreviewedUnreacted);
            $this->incrementMetric(self::KEY_FILES_UNREACTED_PREVIEWED_NOT_BLACKLISTED, $newlyPreviewedUnreacted);
        }
    }

    /**
     * Apply preview metrics before preview_count is reset to zero.
     *
     * @param  array<int>  $fileIds
     */
    public function applyPreviewReset(array $fileIds): void
    {
        if (empty($fileIds)) {
            return;
        }

        $resetUnreacted = DB::table('files')
            ->whereIn('id', $fileIds)
            ->whereNull('blacklisted_at')
            ->where('previewed_count', '>', 0)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('reactions')
                    ->whereColumn('reactions.file_id', 'files.id');
            })
            ->count();

        if ($resetUnreacted > 0) {
            $this->incrementMetric(self::KEY_FILES_UNREACTED_PREVIEWED_NOT_BLACKLISTED, -$resetUnreacted);
            $this->incrementMetric(self::KEY_FILES_UNREACTED_UNPREVIEWED_NOT_BLACKLISTED, $resetUnreacted);
        }
    }

    /**
     * Apply metrics before blacklisted files are forced to the feed-removed preview count.
     *
     * @param  array<int>  $fileIds
     */
    public function applyBlacklistedFeedRemovedMark(array $fileIds): void
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
            $this->incrementMetric(self::KEY_FILES_BLACKLISTED_FEED_REMOVED, $count);
        }
    }

    /**
     * Apply metrics before blacklisted files are moved below the feed-removed preview count.
     *
     * @param  array<int>  $fileIds
     */
    public function applyBlacklistedFeedRemovedClear(array $fileIds): void
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
            $this->incrementMetric(self::KEY_FILES_BLACKLISTED_FEED_REMOVED, -$count);
        }
    }

    /**
     * Apply metrics for downloading a file.
     */
    public function applyDownload(File $file, bool $wasDownloaded): void
    {
        if ($wasDownloaded) {
            return;
        }

        $this->incrementMetric(self::KEY_FILES_DOWNLOADED, 1);
        $this->incrementContainersForFileIds([$file->id], 'files_downloaded', 1);
    }

    /**
     * Apply metrics for clearing download state on a file.
     */
    public function applyDownloadClear(File $file, bool $wasDownloaded): void
    {
        if (! $wasDownloaded) {
            return;
        }

        $this->incrementMetric(self::KEY_FILES_DOWNLOADED, -1);
        $this->incrementContainersForFileIds([$file->id], 'files_downloaded', -1);
    }

    /**
     * Recompute all metrics and container counters from the database.
     */
    public function syncAll(): void
    {
        $this->syncFileMetrics();
        app(ContainerMetricsSyncService::class)->sync($this);
    }

    private function syncFileMetrics(): void
    {
        $fileCounts = File::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN not_found = 1 THEN 1 ELSE 0 END) as not_found_total')
            ->selectRaw('SUM(CASE WHEN downloaded = 1 THEN 1 ELSE 0 END) as downloaded_total')
            ->selectRaw("SUM(CASE WHEN source IN ('local', 'Local') THEN 1 ELSE 0 END) as local_total")
            ->selectRaw('SUM(CASE WHEN blacklisted_at IS NOT NULL THEN 1 ELSE 0 END) as blacklisted_total')
            ->selectRaw('SUM(CASE WHEN blacklisted_at IS NOT NULL AND auto_blacklisted = 0 THEN 1 ELSE 0 END) as blacklisted_manual_total')
            ->selectRaw('SUM(CASE WHEN blacklisted_at IS NOT NULL AND previewed_count >= '.FilePreviewService::FEED_REMOVED_PREVIEW_COUNT.' THEN 1 ELSE 0 END) as blacklisted_feed_removed_total')
            ->selectRaw('SUM(CASE WHEN auto_blacklisted = 1 THEN 1 ELSE 0 END) as auto_blacklisted_total')
            ->first();

        $unreactedCounts = File::query()
            ->whereNull('blacklisted_at')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('reactions')
                    ->whereColumn('reactions.file_id', 'files.id');
            })
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN previewed_count > 0 THEN 1 ELSE 0 END) as previewed_total')
            ->first();
        $unreactedNotBlacklisted = (int) ($unreactedCounts->total ?? 0);
        $unreactedPreviewed = (int) ($unreactedCounts->previewed_total ?? 0);
        $unreactedUnpreviewed = max(0, $unreactedNotBlacklisted - $unreactedPreviewed);

        $reactionCounts = Reaction::query()
            ->select('type')
            ->selectRaw('COUNT(DISTINCT file_id) as total')
            ->whereIn('type', ['love', 'like', 'funny'])
            ->groupBy('type')
            ->pluck('total', 'type');

        $this->setMetric(self::KEY_FILES_TOTAL, (int) ($fileCounts->total ?? 0), 'Total files');
        $this->setMetric(self::KEY_FILES_DOWNLOADED, (int) ($fileCounts->downloaded_total ?? 0), 'Downloaded files');
        $this->setMetric(self::KEY_FILES_LOCAL, (int) ($fileCounts->local_total ?? 0), 'Local files');
        $this->setMetric(self::KEY_FILES_NOT_FOUND, (int) ($fileCounts->not_found_total ?? 0), 'Not found files');
        $this->setMetric(self::KEY_FILES_BLACKLISTED_TOTAL, (int) ($fileCounts->blacklisted_total ?? 0), 'Blacklisted files');
        $this->setMetric(self::KEY_FILES_BLACKLISTED_MANUAL, (int) ($fileCounts->blacklisted_manual_total ?? 0), 'Manual blacklisted files');
        $this->setMetric(self::KEY_FILES_BLACKLISTED_FEED_REMOVED, (int) ($fileCounts->blacklisted_feed_removed_total ?? 0), 'Feed-removed blacklisted files');
        $this->setMetric(self::KEY_FILES_AUTO_BLACKLISTED, (int) ($fileCounts->auto_blacklisted_total ?? 0), 'Auto blacklisted files');
        $this->setMetric(self::KEY_FILES_UNREACTED_NOT_BLACKLISTED, $unreactedNotBlacklisted, 'Unreacted, not blacklisted');
        $this->setMetric(self::KEY_FILES_UNREACTED_PREVIEWED_NOT_BLACKLISTED, $unreactedPreviewed, 'Unreacted previewed, not blacklisted');
        $this->setMetric(self::KEY_FILES_UNREACTED_UNPREVIEWED_NOT_BLACKLISTED, $unreactedUnpreviewed, 'Unreacted not previewed, not blacklisted');
        $this->setMetric(self::KEY_REACTIONS_LOVE, (int) ($reactionCounts['love'] ?? 0), 'Files with love reactions');
        $this->setMetric(self::KEY_REACTIONS_LIKE, (int) ($reactionCounts['like'] ?? 0), 'Files with like reactions');
        $this->setMetric(self::KEY_REACTIONS_FUNNY, (int) ($reactionCounts['funny'] ?? 0), 'Files with funny reactions');
    }

    private function incrementUnreactedNotBlacklistedForFile(File $file, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $this->incrementMetric(self::KEY_FILES_UNREACTED_NOT_BLACKLISTED, $delta);
        $this->incrementMetric($this->unreactedPreviewMetricKey($file), $delta);
    }

    /**
     * @param  iterable<int, object>  $rows
     */
    private function incrementUnreactedPreviewBuckets(iterable $rows, int $direction): void
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

        $this->incrementMetric(self::KEY_FILES_UNREACTED_NOT_BLACKLISTED, $total * $direction);
        if ($previewed > 0) {
            $this->incrementMetric(self::KEY_FILES_UNREACTED_PREVIEWED_NOT_BLACKLISTED, $previewed * $direction);
        }
        if ($unpreviewed > 0) {
            $this->incrementMetric(self::KEY_FILES_UNREACTED_UNPREVIEWED_NOT_BLACKLISTED, $unpreviewed * $direction);
        }
    }

    private function unreactedPreviewMetricKey(File $file): string
    {
        return (int) $file->previewed_count > 0
            ? self::KEY_FILES_UNREACTED_PREVIEWED_NOT_BLACKLISTED
            : self::KEY_FILES_UNREACTED_UNPREVIEWED_NOT_BLACKLISTED;
    }

    private function willBeFeedRemoved(File $file, ?int $minimumPreviewedCount): bool
    {
        return (int) $file->previewed_count >= FilePreviewService::FEED_REMOVED_PREVIEW_COUNT
            || (is_int($minimumPreviewedCount) && $minimumPreviewedCount >= FilePreviewService::FEED_REMOVED_PREVIEW_COUNT);
    }
}
