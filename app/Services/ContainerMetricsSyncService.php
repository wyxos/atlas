<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ContainerMetricsSyncService
{
    private const int UPDATE_BATCH_SIZE = 1000;

    public function sync(MetricsService $metrics): void
    {
        DB::table('containers')->update([
            'files_total' => 0,
            'files_downloaded' => 0,
            'files_blacklisted' => 0,
            'files_favorited' => 0,
            'updated_at' => now(),
        ]);

        $this->syncFileCounters();
        $this->syncFavoriteCounters();

        $metrics->setMetric(
            MetricsService::KEY_CONTAINERS_TOTAL,
            DB::table('containers')->where('type', '!=', 'Post')->count(),
            'Total containers'
        );
        $metrics->setMetric(
            MetricsService::KEY_CONTAINERS_BLACKLISTED,
            DB::table('containers')->where('type', '!=', 'Post')->whereNotNull('blacklisted_at')->count(),
            'Blacklisted containers'
        );
    }

    private function syncFileCounters(): void
    {
        $totals = DB::table('container_file')
            ->join('files', 'files.id', '=', 'container_file.file_id')
            ->select('container_file.container_id')
            ->selectRaw('COUNT(*) as files_total')
            ->selectRaw('SUM(CASE WHEN files.downloaded = 1 THEN 1 ELSE 0 END) as files_downloaded')
            ->selectRaw('SUM(CASE WHEN files.blacklisted_at IS NOT NULL THEN 1 ELSE 0 END) as files_blacklisted')
            ->groupBy('container_file.container_id')
            ->get();

        $timestamp = now();
        $grammar = DB::query()->getGrammar();
        $wrappedId = $grammar->wrap('id');
        $wrappedTotal = $grammar->wrap('files_total');
        $wrappedDownloaded = $grammar->wrap('files_downloaded');
        $wrappedBlacklisted = $grammar->wrap('files_blacklisted');

        foreach ($totals->chunk(self::UPDATE_BATCH_SIZE) as $batch) {
            $totalContainerIds = [];
            $totalCases = [];
            $downloadedCases = [];
            $blacklistedCases = [];

            foreach ($batch as $row) {
                $containerId = (int) $row->container_id;
                $totalContainerIds[] = $containerId;
                $totalCases[] = "WHEN {$containerId} THEN ".(int) $row->files_total;
                $downloadedCases[] = "WHEN {$containerId} THEN ".(int) $row->files_downloaded;
                $blacklistedCases[] = "WHEN {$containerId} THEN ".(int) $row->files_blacklisted;
            }

            if ($totalContainerIds === []) {
                continue;
            }

            DB::table('containers')
                ->whereIn('id', $totalContainerIds)
                ->update([
                    'files_total' => DB::raw('CASE '.$wrappedId.' '.implode(' ', $totalCases)." ELSE {$wrappedTotal} END"),
                    'files_downloaded' => DB::raw('CASE '.$wrappedId.' '.implode(' ', $downloadedCases)." ELSE {$wrappedDownloaded} END"),
                    'files_blacklisted' => DB::raw('CASE '.$wrappedId.' '.implode(' ', $blacklistedCases)." ELSE {$wrappedBlacklisted} END"),
                    'updated_at' => $timestamp,
                ]);
        }
    }

    private function syncFavoriteCounters(): void
    {
        $favorites = DB::table('container_file')
            ->join('reactions', function ($join) {
                $join->on('reactions.file_id', '=', 'container_file.file_id')
                    ->where('reactions.type', '=', 'love');
            })
            ->select('container_file.container_id')
            ->selectRaw('COUNT(DISTINCT container_file.file_id) as files_favorited')
            ->groupBy('container_file.container_id')
            ->get();

        $timestamp = now();
        $grammar = DB::query()->getGrammar();
        $wrappedId = $grammar->wrap('id');
        $wrappedFavorited = $grammar->wrap('files_favorited');

        foreach ($favorites->chunk(self::UPDATE_BATCH_SIZE) as $batch) {
            $favoriteContainerIds = [];
            $favoriteCases = [];

            foreach ($batch as $row) {
                $containerId = (int) $row->container_id;
                $favoriteContainerIds[] = $containerId;
                $favoriteCases[] = "WHEN {$containerId} THEN ".(int) $row->files_favorited;
            }

            if ($favoriteContainerIds === []) {
                continue;
            }

            DB::table('containers')
                ->whereIn('id', $favoriteContainerIds)
                ->update([
                    'files_favorited' => DB::raw('CASE '.$wrappedId.' '.implode(' ', $favoriteCases)." ELSE {$wrappedFavorited} END"),
                    'updated_at' => $timestamp,
                ]);
        }
    }
}
