<?php

namespace App\Http\Controllers;

use App\Services\MetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardMetricsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $metricsService = app(MetricsService::class);
        if (DB::table('metrics')->count() === 0) {
            $metricsService->syncAll();
        }

        return response()->json($this->metricsFromTable());
    }

    private function metricsFromTable(): array
    {
        $metrics = app(MetricsService::class)->getMetrics([
            MetricsService::KEY_FILES_TOTAL,
            MetricsService::KEY_FILES_DOWNLOADED,
            MetricsService::KEY_FILES_LOCAL,
            MetricsService::KEY_FILES_NOT_FOUND,
            MetricsService::KEY_FILES_BLACKLISTED_TOTAL,
            MetricsService::KEY_FILES_BLACKLISTED_MANUAL,
            MetricsService::KEY_FILES_BLACKLISTED_AUTO,
            MetricsService::KEY_FILES_UNREACTED_NOT_BLACKLISTED,
            MetricsService::KEY_REACTIONS_LOVE,
            MetricsService::KEY_REACTIONS_LIKE,
            MetricsService::KEY_REACTIONS_DISLIKE,
            MetricsService::KEY_REACTIONS_FUNNY,
            MetricsService::KEY_CONTAINERS_TOTAL,
            MetricsService::KEY_CONTAINERS_BLACKLISTED,
        ]);

        $reactions = [
            'love' => $metrics[MetricsService::KEY_REACTIONS_LOVE] ?? 0,
            'like' => $metrics[MetricsService::KEY_REACTIONS_LIKE] ?? 0,
            'dislike' => $metrics[MetricsService::KEY_REACTIONS_DISLIKE] ?? 0,
            'funny' => $metrics[MetricsService::KEY_REACTIONS_FUNNY] ?? 0,
        ];

        return [
            'files' => [
                'total' => $metrics[MetricsService::KEY_FILES_TOTAL] ?? 0,
                'downloaded' => $metrics[MetricsService::KEY_FILES_DOWNLOADED] ?? 0,
                'local' => $metrics[MetricsService::KEY_FILES_LOCAL] ?? 0,
                'non_local' => max(0, ($metrics[MetricsService::KEY_FILES_TOTAL] ?? 0) - ($metrics[MetricsService::KEY_FILES_LOCAL] ?? 0)),
                'reactions' => $reactions,
                'blacklisted' => [
                    'total' => $metrics[MetricsService::KEY_FILES_BLACKLISTED_TOTAL] ?? 0,
                    'manual' => $metrics[MetricsService::KEY_FILES_BLACKLISTED_MANUAL] ?? 0,
                    'auto' => $metrics[MetricsService::KEY_FILES_BLACKLISTED_AUTO] ?? 0,
                ],
                'not_found' => $metrics[MetricsService::KEY_FILES_NOT_FOUND] ?? 0,
                'unreacted_not_blacklisted' => $metrics[MetricsService::KEY_FILES_UNREACTED_NOT_BLACKLISTED] ?? 0,
            ],
            'containers' => $this->containerMetrics(),
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
                'files_count' => (int) $row->files_count,
            ])
            ->values()
            ->all();
    }
}
