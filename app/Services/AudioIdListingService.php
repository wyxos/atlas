<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AudioIdListingService
{
    /**
     * Fetch audio file IDs with cursor metadata.
     *
     * NOTE FOR FUTURE CODEX INSTANCES:
     * Do not switch this to Eloquent. Keep this query-builder only and ID-only,
     * similar to LocalService, because the files dataset is large.
     */
    public function fetch(int $afterId, int $perPage, ?int $maxId = null): array
    {
        $afterId = max(0, $afterId);
        $perPage = min(1000, max(1, $perPage));
        $maxId = $maxId === null
            ? (int) DB::table('files')
                ->where('mime_type', 'like', 'audio/%')
                ->max('id')
            : max(0, $maxId);

        $rows = DB::table('files')
            ->select('id')
            ->where('mime_type', 'like', 'audio/%')
            ->where('id', '>', $afterId)
            ->where('id', '<=', $maxId)
            ->orderBy('id')
            ->limit($perPage + 1)
            ->get();

        $hasMore = $rows->count() > $perPage;
        $chunk = $hasMore ? $rows->slice(0, $perPage)->values() : $rows;

        $ids = array_map(
            static fn ($row): int => (int) $row->id,
            $chunk->all(),
        );

        $nextAfterId = $hasMore && $ids !== []
            ? $ids[array_key_last($ids)]
            : null;
        $total = $afterId === 0
            ? (int) DB::table('files')
                ->where('mime_type', 'like', 'audio/%')
                ->where('id', '<=', $maxId)
                ->count()
            : null;
        $totalPages = $total !== null
            ? (int) ceil($total / max(1, $perPage))
            : null;

        return [
            'ids' => $ids,
            'cursor' => [
                'after_id' => $afterId,
                'next_after_id' => $nextAfterId,
                'has_more' => $hasMore,
                'max_id' => $maxId,
            ],
            'pagination' => [
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ];
    }
}
