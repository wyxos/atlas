<?php

namespace App\Services;

use App\Models\File;
use App\Models\Reaction;
use Illuminate\Support\Facades\DB;

class MetricsService
{
    public const string KEY_FILES_TOTAL = 'files_total';

    public const string KEY_FILES_DOWNLOADED = 'files_downloaded';

    public const string KEY_FILES_LOCAL = 'files_local';

    public const string KEY_FILES_NOT_FOUND = 'files_not_found';

    public const string KEY_FILES_BLACKLISTED_TOTAL = 'files_blacklisted_total';

    public const string KEY_FILES_BLACKLISTED_MANUAL = 'files_blacklisted_manual';

    public const string KEY_FILES_BLACKLISTED_AUTO = 'files_blacklisted_auto';

    public const string KEY_FILES_UNREACTED_NOT_BLACKLISTED = 'files_unreacted_not_blacklisted';

    public const string KEY_REACTIONS_LOVE = 'reactions_love';

    public const string KEY_REACTIONS_LIKE = 'reactions_like';

    public const string KEY_REACTIONS_DISLIKE = 'reactions_dislike';

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
            $updates = [
                'value' => DB::raw("CASE WHEN value + {$delta} < 0 THEN 0 ELSE value + {$delta} END"),
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

        foreach ($countsByContainer as $containerId => $count) {
            if ($count === 0) {
                continue;
            }
            DB::table('containers')
                ->where('id', $containerId)
                ->update([
                    $column => DB::raw("CASE WHEN {$column} + {$count} < 0 THEN 0 ELSE {$column} + {$count} END"),
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
                'dislike' => self::KEY_REACTIONS_DISLIKE,
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
            $this->incrementMetric(self::KEY_FILES_UNREACTED_NOT_BLACKLISTED, $afterCounted ? 1 : -1);
        }

        if ($loveDelta !== 0) {
            $this->incrementContainersForFileIds([$file->id], 'files_favorited', $loveDelta);
        }
    }

    /**
     * Apply metrics for auto-dislike inserts (before reactions are inserted).
     *
     * @param  array<int>  $fileIds
     */
    public function applyDislikeInsert(array $fileIds): void
    {
        if (empty($fileIds)) {
            return;
        }

        $existingDislike = Reaction::query()
            ->whereIn('file_id', $fileIds)
            ->where('type', 'dislike')
            ->distinct()
            ->pluck('file_id')
            ->map(fn ($value) => (int) $value)
            ->all();

        $existingDislikeMap = array_flip($existingDislike);
        $newDislikeIds = array_values(array_filter($fileIds, fn ($id) => ! isset($existingDislikeMap[$id])));

        if (! empty($newDislikeIds)) {
            $this->incrementMetric(self::KEY_REACTIONS_DISLIKE, count($newDislikeIds));
        }

        $filesWithoutReactions = DB::table('files')
            ->whereIn('id', $fileIds)
            ->whereNull('blacklisted_at')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('reactions')
                    ->whereColumn('reactions.file_id', 'files.id');
            })
            ->pluck('id')
            ->map(fn ($value) => (int) $value)
            ->all();

        if (! empty($filesWithoutReactions)) {
            $this->incrementMetric(self::KEY_FILES_UNREACTED_NOT_BLACKLISTED, -count($filesWithoutReactions));
        }
    }

    /**
     * Apply metrics for bulk blacklisting (before updating files).
     *
     * @param  array<int>  $fileIds
     */
    public function applyBlacklistAdd(array $fileIds, bool $manual): void
    {
        if (empty($fileIds)) {
            return;
        }

        $eligibleIds = File::query()
            ->whereIn('id', $fileIds)
            ->whereNull('blacklisted_at')
            ->pluck('id')
            ->map(fn ($value) => (int) $value)
            ->all();

        if (empty($eligibleIds)) {
            return;
        }

        $this->incrementMetric(self::KEY_FILES_BLACKLISTED_TOTAL, count($eligibleIds));
        $this->incrementMetric($manual ? self::KEY_FILES_BLACKLISTED_MANUAL : self::KEY_FILES_BLACKLISTED_AUTO, count($eligibleIds));

        $filesWithoutReactions = DB::table('files')
            ->whereIn('id', $eligibleIds)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('reactions')
                    ->whereColumn('reactions.file_id', 'files.id');
            })
            ->pluck('id')
            ->map(fn ($value) => (int) $value)
            ->all();

        if (! empty($filesWithoutReactions)) {
            $this->incrementMetric(self::KEY_FILES_UNREACTED_NOT_BLACKLISTED, -count($filesWithoutReactions));
        }

        $this->incrementContainersForFileIds($eligibleIds, 'files_blacklisted', 1);
    }

    /**
     * Apply metrics for clearing a blacklist on a single file.
     */
    public function applyBlacklistClear(File $file, bool $wasManual, bool $adjustUnreacted = true): void
    {
        $this->incrementMetric(self::KEY_FILES_BLACKLISTED_TOTAL, -1);
        $this->incrementMetric($wasManual ? self::KEY_FILES_BLACKLISTED_MANUAL : self::KEY_FILES_BLACKLISTED_AUTO, -1);

        if ($adjustUnreacted) {
            $hasReactions = Reaction::where('file_id', $file->id)->exists();
            if (! $hasReactions) {
                $this->incrementMetric(self::KEY_FILES_UNREACTED_NOT_BLACKLISTED, 1);
            }
        }

        $this->incrementContainersForFileIds([$file->id], 'files_blacklisted', -1);
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
        $this->syncContainerMetrics();
    }

    private function syncFileMetrics(): void
    {
        $fileCounts = File::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN not_found = 1 THEN 1 ELSE 0 END) as not_found_total')
            ->selectRaw('SUM(CASE WHEN downloaded = 1 THEN 1 ELSE 0 END) as downloaded_total')
            ->selectRaw("SUM(CASE WHEN source IN ('local', 'Local') THEN 1 ELSE 0 END) as local_total")
            ->selectRaw('SUM(CASE WHEN blacklisted_at IS NOT NULL THEN 1 ELSE 0 END) as blacklisted_total')
            ->selectRaw('SUM(CASE WHEN blacklisted_at IS NOT NULL AND blacklist_reason IS NOT NULL AND blacklist_reason <> "" THEN 1 ELSE 0 END) as blacklisted_manual')
            ->selectRaw('SUM(CASE WHEN blacklisted_at IS NOT NULL AND (blacklist_reason IS NULL OR blacklist_reason = "") THEN 1 ELSE 0 END) as blacklisted_auto')
            ->first();

        $unreactedNotBlacklisted = File::query()
            ->whereNull('blacklisted_at')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('reactions')
                    ->whereColumn('reactions.file_id', 'files.id');
            })
            ->count();

        $reactionCounts = Reaction::query()
            ->select('type')
            ->selectRaw('COUNT(DISTINCT file_id) as total')
            ->whereIn('type', ['love', 'like', 'dislike', 'funny'])
            ->groupBy('type')
            ->pluck('total', 'type');

        $this->setMetric(self::KEY_FILES_TOTAL, (int) ($fileCounts->total ?? 0), 'Total files');
        $this->setMetric(self::KEY_FILES_DOWNLOADED, (int) ($fileCounts->downloaded_total ?? 0), 'Downloaded files');
        $this->setMetric(self::KEY_FILES_LOCAL, (int) ($fileCounts->local_total ?? 0), 'Local files');
        $this->setMetric(self::KEY_FILES_NOT_FOUND, (int) ($fileCounts->not_found_total ?? 0), 'Not found files');
        $this->setMetric(self::KEY_FILES_BLACKLISTED_TOTAL, (int) ($fileCounts->blacklisted_total ?? 0), 'Blacklisted files');
        $this->setMetric(self::KEY_FILES_BLACKLISTED_MANUAL, (int) ($fileCounts->blacklisted_manual ?? 0), 'Manually blacklisted files');
        $this->setMetric(self::KEY_FILES_BLACKLISTED_AUTO, (int) ($fileCounts->blacklisted_auto ?? 0), 'Auto blacklisted files');
        $this->setMetric(self::KEY_FILES_UNREACTED_NOT_BLACKLISTED, (int) $unreactedNotBlacklisted, 'Unreacted, not blacklisted');
        $this->setMetric(self::KEY_REACTIONS_LOVE, (int) ($reactionCounts['love'] ?? 0), 'Files with love reactions');
        $this->setMetric(self::KEY_REACTIONS_LIKE, (int) ($reactionCounts['like'] ?? 0), 'Files with like reactions');
        $this->setMetric(self::KEY_REACTIONS_DISLIKE, (int) ($reactionCounts['dislike'] ?? 0), 'Files with dislike reactions');
        $this->setMetric(self::KEY_REACTIONS_FUNNY, (int) ($reactionCounts['funny'] ?? 0), 'Files with funny reactions');
    }

    private function syncContainerMetrics(): void
    {
        DB::table('containers')->update([
            'files_total' => 0,
            'files_downloaded' => 0,
            'files_blacklisted' => 0,
            'files_favorited' => 0,
            'updated_at' => now(),
        ]);

        $totals = DB::table('container_file')
            ->join('files', 'files.id', '=', 'container_file.file_id')
            ->select('container_file.container_id')
            ->selectRaw('COUNT(*) as files_total')
            ->selectRaw('SUM(CASE WHEN files.downloaded = 1 THEN 1 ELSE 0 END) as files_downloaded')
            ->selectRaw('SUM(CASE WHEN files.blacklisted_at IS NOT NULL THEN 1 ELSE 0 END) as files_blacklisted')
            ->groupBy('container_file.container_id')
            ->get();

        foreach ($totals as $row) {
            DB::table('containers')
                ->where('id', $row->container_id)
                ->update([
                    'files_total' => (int) $row->files_total,
                    'files_downloaded' => (int) $row->files_downloaded,
                    'files_blacklisted' => (int) $row->files_blacklisted,
                    'updated_at' => now(),
                ]);
        }

        $favorites = DB::table('container_file')
            ->join('reactions', function ($join) {
                $join->on('reactions.file_id', '=', 'container_file.file_id')
                    ->where('reactions.type', '=', 'love');
            })
            ->select('container_file.container_id')
            ->selectRaw('COUNT(DISTINCT container_file.file_id) as files_favorited')
            ->groupBy('container_file.container_id')
            ->get();

        foreach ($favorites as $row) {
            DB::table('containers')
                ->where('id', $row->container_id)
                ->update([
                    'files_favorited' => (int) $row->files_favorited,
                    'updated_at' => now(),
                ]);
        }

        $this->setMetric(
            self::KEY_CONTAINERS_TOTAL,
            DB::table('containers')->where('type', '!=', 'Post')->count(),
            'Total containers'
        );
        $this->setMetric(
            self::KEY_CONTAINERS_BLACKLISTED,
            DB::table('containers')->where('type', '!=', 'Post')->whereNotNull('blacklisted_at')->count(),
            'Blacklisted containers'
        );
    }
}
