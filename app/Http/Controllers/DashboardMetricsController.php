<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Reaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Typesense\Client;

class DashboardMetricsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        if (config('scout.driver') !== 'typesense') {
            return response()->json($this->databaseMetrics());
        }

        return response()->json($this->typesenseMetrics());
    }

    private function databaseMetrics(): array
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

        $reactions = [
            'love' => (int) ($reactionCounts['love'] ?? 0),
            'like' => (int) ($reactionCounts['like'] ?? 0),
            'dislike' => (int) ($reactionCounts['dislike'] ?? 0),
            'funny' => (int) ($reactionCounts['funny'] ?? 0),
        ];

        return [
            'files' => [
                'total' => (int) ($fileCounts->total ?? 0),
                'downloaded' => (int) ($fileCounts->downloaded_total ?? 0),
                'local' => (int) ($fileCounts->local_total ?? 0),
                'non_local' => max(0, (int) ($fileCounts->total ?? 0) - (int) ($fileCounts->local_total ?? 0)),
                'reactions' => $reactions,
                'blacklisted' => [
                    'total' => (int) ($fileCounts->blacklisted_total ?? 0),
                    'manual' => (int) ($fileCounts->blacklisted_manual ?? 0),
                    'auto' => (int) ($fileCounts->blacklisted_auto ?? 0),
                ],
                'not_found' => (int) ($fileCounts->not_found_total ?? 0),
                'unreacted_not_blacklisted' => (int) $unreactedNotBlacklisted,
            ],
            'containers' => $this->containerMetrics(),
        ];
    }

    private function typesenseMetrics(): array
    {
        $client = new Client(config('scout.typesense.client-settings'));
        $collection = config('scout.prefix').(new File)->searchableAs();
        $queryBy = config('scout.typesense.model-settings')[File::class]['search-parameters']['query_by'] ?? 'filename';
        $baseParams = [
            'q' => '*',
            'query_by' => $queryBy,
            'per_page' => 0,
            'page' => 1,
        ];

        $facetResponse = $client->collections[$collection]->documents->search([
            ...$baseParams,
            'facet_by' => 'blacklisted,blacklist_type,has_reactions,has_love,has_like,has_dislike,has_funny,not_found,downloaded,source',
        ]);

        $facetCounts = collect($facetResponse['facet_counts'] ?? [])->keyBy('field_name');

        $total = (int) ($facetResponse['found'] ?? 0);
        $blacklistedTotal = $this->facetValueCount($facetCounts->get('blacklisted'), 'true');
        $blacklistedManual = $this->facetValueCount($facetCounts->get('blacklist_type'), 'manual');
        $blacklistedAuto = $this->facetValueCount($facetCounts->get('blacklist_type'), 'auto');
        $notFound = $this->facetValueCount($facetCounts->get('not_found'), 'true');
        $downloaded = $this->facetValueCount($facetCounts->get('downloaded'), 'true');
        $local = $this->facetValueCountAny($facetCounts->get('source'), ['local', 'Local']);

        $reactions = [
            'love' => $this->facetValueCount($facetCounts->get('has_love'), 'true'),
            'like' => $this->facetValueCount($facetCounts->get('has_like'), 'true'),
            'dislike' => $this->facetValueCount($facetCounts->get('has_dislike'), 'true'),
            'funny' => $this->facetValueCount($facetCounts->get('has_funny'), 'true'),
        ];

        $unreactedResponse = $client->collections[$collection]->documents->search([
            ...$baseParams,
            'filter_by' => 'blacklisted:=false && has_reactions:=false',
        ]);

        $unreactedNotBlacklisted = (int) ($unreactedResponse['found'] ?? 0);

        return [
            'files' => [
                'total' => $total,
                'downloaded' => $downloaded,
                'local' => $local,
                'non_local' => max(0, $total - $local),
                'reactions' => $reactions,
                'blacklisted' => [
                    'total' => $blacklistedTotal,
                    'manual' => $blacklistedManual,
                    'auto' => $blacklistedAuto,
                ],
                'not_found' => $notFound,
                'unreacted_not_blacklisted' => $unreactedNotBlacklisted,
            ],
            'containers' => $this->containerMetrics(),
        ];
    }

    private function containerMetrics(): array
    {
        $base = DB::table('containers')
            ->where('type', '!=', 'Post');

        $total = (clone $base)->count();
        $blacklisted = (clone $base)->whereNotNull('blacklisted_at')->count();

        $topDownloads = $this->topContainersByFiles(
            fn ($query) => $query->where('files.downloaded', true)
        );

        $topFavorites = $this->topContainersByFiles(
            fn ($query) => $query
                ->join('reactions', function ($join) {
                    $join->on('reactions.file_id', '=', 'files.id')
                        ->where('reactions.type', '=', 'love');
                })
        );

        $topBlacklisted = $this->topContainersByFiles(
            fn ($query) => $query->whereNotNull('files.blacklisted_at')
        );

        return [
            'total' => $total,
            'blacklisted' => $blacklisted,
            'top_downloads' => $topDownloads,
            'top_favorites' => $topFavorites,
            'top_blacklisted' => $topBlacklisted,
        ];
    }

    /**
     * @param  callable(\Illuminate\Database\Query\Builder): \Illuminate\Database\Query\Builder  $applyFilters
     * @return array<int, array<string, mixed>>
     */
    private function topContainersByFiles(callable $applyFilters): array
    {
        $query = DB::table('containers')
            ->join('container_file', 'containers.id', '=', 'container_file.container_id')
            ->join('files', 'files.id', '=', 'container_file.file_id')
            ->where('containers.type', '!=', 'Post');

        $query = $applyFilters($query);

        return $query
            ->select([
                'containers.id',
                'containers.type',
                'containers.source',
                'containers.source_id',
                'containers.referrer',
                DB::raw('COUNT(DISTINCT files.id) as files_count'),
            ])
            ->groupBy('containers.id', 'containers.type', 'containers.source', 'containers.source_id', 'containers.referrer')
            ->orderByDesc('files_count')
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

    /**
     * @param  array<string, mixed>|null  $facet
     */
    private function facetValueCount(?array $facet, string $value): int
    {
        if (! $facet || empty($facet['counts'])) {
            return 0;
        }

        foreach ($facet['counts'] as $count) {
            if (($count['value'] ?? null) === $value) {
                return (int) ($count['count'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>|null  $facet
     * @param  array<int, string>  $values
     */
    private function facetValueCountAny(?array $facet, array $values): int
    {
        if (! $facet || empty($facet['counts'])) {
            return 0;
        }

        $total = 0;
        foreach ($facet['counts'] as $count) {
            if (in_array($count['value'] ?? null, $values, true)) {
                $total += (int) ($count['count'] ?? 0);
            }
        }

        return $total;
    }
}
