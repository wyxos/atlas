<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Reaction;
use Illuminate\Http\JsonResponse;
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
                'reactions' => $reactions,
                'blacklisted' => [
                    'total' => (int) ($fileCounts->blacklisted_total ?? 0),
                    'manual' => (int) ($fileCounts->blacklisted_manual ?? 0),
                    'auto' => (int) ($fileCounts->blacklisted_auto ?? 0),
                ],
                'unreacted_not_blacklisted' => (int) $unreactedNotBlacklisted,
            ],
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
            'facet_by' => 'blacklisted,blacklist_type,has_reactions,has_love,has_like,has_dislike,has_funny',
        ]);

        $facetCounts = collect($facetResponse['facet_counts'] ?? [])->keyBy('field_name');

        $total = (int) ($facetResponse['found'] ?? 0);
        $blacklistedTotal = $this->facetValueCount($facetCounts->get('blacklisted'), 'true');
        $blacklistedManual = $this->facetValueCount($facetCounts->get('blacklist_type'), 'manual');
        $blacklistedAuto = $this->facetValueCount($facetCounts->get('blacklist_type'), 'auto');

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
                'reactions' => $reactions,
                'blacklisted' => [
                    'total' => $blacklistedTotal,
                    'manual' => $blacklistedManual,
                    'auto' => $blacklistedAuto,
                ],
                'unreacted_not_blacklisted' => $unreactedNotBlacklisted,
            ],
        ];
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
}
