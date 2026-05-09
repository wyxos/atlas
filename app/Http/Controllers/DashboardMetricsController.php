<?php

namespace App\Http\Controllers;

use App\Services\MetricsService;
use App\Support\ContainerBrowseTabPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardMetricsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $metricsService = app(MetricsService::class);
        $requiredMetricKeys = $this->metricKeys();
        if (DB::table('metrics')->whereIn('key', $requiredMetricKeys)->count() < count($requiredMetricKeys)) {
            $metricsService->syncAll();
        }

        return response()->json($this->metricsFromTable());
    }

    private function metricsFromTable(): array
    {
        $metrics = app(MetricsService::class)->getMetrics($this->metricKeys());

        $reactions = [
            'love' => $metrics[MetricsService::KEY_REACTIONS_LOVE] ?? 0,
            'like' => $metrics[MetricsService::KEY_REACTIONS_LIKE] ?? 0,
            'funny' => $metrics[MetricsService::KEY_REACTIONS_FUNNY] ?? 0,
        ];

        return [
            'files' => [
                'total' => $metrics[MetricsService::KEY_FILES_TOTAL] ?? 0,
                'downloaded' => $metrics[MetricsService::KEY_FILES_DOWNLOADED] ?? 0,
                'stored' => $metrics[MetricsService::KEY_FILES_WITH_PATH] ?? 0,
                'records_only' => max(0, ($metrics[MetricsService::KEY_FILES_TOTAL] ?? 0) - ($metrics[MetricsService::KEY_FILES_WITH_PATH] ?? 0)),
                'local' => $metrics[MetricsService::KEY_FILES_LOCAL] ?? 0,
                'non_local' => max(0, ($metrics[MetricsService::KEY_FILES_TOTAL] ?? 0) - ($metrics[MetricsService::KEY_FILES_LOCAL] ?? 0)),
                'local_available' => $metrics[MetricsService::KEY_FILES_LOCAL_AVAILABLE] ?? 0,
                'non_local_available' => $metrics[MetricsService::KEY_FILES_NON_LOCAL_AVAILABLE] ?? 0,
                'reactions' => $reactions,
                'reacted' => $metrics[MetricsService::KEY_FILES_REACTED] ?? 0,
                'unreacted' => max(0, ($metrics[MetricsService::KEY_FILES_TOTAL] ?? 0) - ($metrics[MetricsService::KEY_FILES_REACTED] ?? 0)),
                'blacklisted' => $metrics[MetricsService::KEY_FILES_BLACKLISTED_TOTAL] ?? 0,
                'blacklisted_manual' => $metrics[MetricsService::KEY_FILES_BLACKLISTED_MANUAL] ?? 0,
                'blacklisted_feed_removed' => $metrics[MetricsService::KEY_FILES_BLACKLISTED_FEED_REMOVED] ?? 0,
                'blacklisted_manual_in_feed' => $metrics[MetricsService::KEY_FILES_BLACKLISTED_MANUAL_IN_FEED] ?? 0,
                'blacklisted_auto_in_feed' => $metrics[MetricsService::KEY_FILES_BLACKLISTED_AUTO_IN_FEED] ?? 0,
                'auto_blacklisted' => $metrics[MetricsService::KEY_FILES_AUTO_BLACKLISTED] ?? 0,
                'not_found' => $metrics[MetricsService::KEY_FILES_NOT_FOUND] ?? 0,
                'unreacted_not_blacklisted' => $metrics[MetricsService::KEY_FILES_UNREACTED_NOT_BLACKLISTED] ?? 0,
                'unreacted_previewed_not_blacklisted' => $metrics[MetricsService::KEY_FILES_UNREACTED_PREVIEWED_NOT_BLACKLISTED] ?? 0,
                'unreacted_unpreviewed_not_blacklisted' => $metrics[MetricsService::KEY_FILES_UNREACTED_UNPREVIEWED_NOT_BLACKLISTED] ?? 0,
            ],
            'containers' => $this->containerMetrics(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function metricKeys(): array
    {
        return [
            MetricsService::KEY_FILES_TOTAL,
            MetricsService::KEY_FILES_DOWNLOADED,
            MetricsService::KEY_FILES_WITH_PATH,
            MetricsService::KEY_FILES_LOCAL,
            MetricsService::KEY_FILES_LOCAL_AVAILABLE,
            MetricsService::KEY_FILES_NON_LOCAL_AVAILABLE,
            MetricsService::KEY_FILES_NOT_FOUND,
            MetricsService::KEY_FILES_REACTED,
            MetricsService::KEY_FILES_BLACKLISTED_TOTAL,
            MetricsService::KEY_FILES_BLACKLISTED_MANUAL,
            MetricsService::KEY_FILES_BLACKLISTED_FEED_REMOVED,
            MetricsService::KEY_FILES_BLACKLISTED_MANUAL_IN_FEED,
            MetricsService::KEY_FILES_BLACKLISTED_AUTO_IN_FEED,
            MetricsService::KEY_FILES_AUTO_BLACKLISTED,
            MetricsService::KEY_FILES_UNREACTED_NOT_BLACKLISTED,
            MetricsService::KEY_FILES_UNREACTED_PREVIEWED_NOT_BLACKLISTED,
            MetricsService::KEY_FILES_UNREACTED_UNPREVIEWED_NOT_BLACKLISTED,
            MetricsService::KEY_REACTIONS_LOVE,
            MetricsService::KEY_REACTIONS_LIKE,
            MetricsService::KEY_REACTIONS_FUNNY,
            MetricsService::KEY_CONTAINERS_TOTAL,
            MetricsService::KEY_CONTAINERS_BLACKLISTED,
        ];
    }

    private function containerMetrics(): array
    {
        $metrics = app(MetricsService::class)->getMetrics([
            MetricsService::KEY_CONTAINERS_TOTAL,
            MetricsService::KEY_CONTAINERS_BLACKLISTED,
        ]);

        $total = $metrics[MetricsService::KEY_CONTAINERS_TOTAL] ?? 0;
        $blacklisted = $metrics[MetricsService::KEY_CONTAINERS_BLACKLISTED] ?? 0;

        $topDownloads = $this->topContainersByColumn('files_downloaded');
        $topFavorites = $this->topContainersByColumn('files_favorited');
        $topBlacklisted = $this->topContainersByColumn('files_blacklisted');

        return [
            'total' => $total,
            'blacklisted' => $blacklisted,
            'top_downloads' => $topDownloads,
            'top_favorites' => $topFavorites,
            'top_blacklisted' => $topBlacklisted,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function topContainersByColumn(string $column): array
    {
        return DB::table('containers')
            ->where('type', '!=', 'Post')
            ->select([
                'id',
                'type',
                'source',
                'source_id',
                'referrer',
                DB::raw("{$column} as files_count"),
            ])
            ->orderByDesc($column)
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'type' => $row->type,
                'source' => $row->source,
                'source_id' => $row->source_id,
                'referrer' => $row->referrer,
                'browse_tab' => ContainerBrowseTabPayload::build([
                    'id' => $row->id,
                    'type' => $row->type,
                    'source' => $row->source,
                    'source_id' => $row->source_id,
                ]),
                'files_count' => (int) $row->files_count,
            ])
            ->values()
            ->all();
    }
}
