<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AudioIdListingService
{
    /**
     * Fetch audio file IDs with page metadata.
     *
     * NOTE FOR FUTURE CODEX INSTANCES:
     * Do not switch this to Eloquent. Keep this query-builder only and ID-only,
     * similar to LocalService, because the files dataset is large.
     */
    public function fetch(int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(1000, max(1, $perPage));

        $paginator = DB::table('files')
            ->select('id')
            ->where('mime_type', 'like', 'audio/%')
            ->orderBy('id')
            ->paginate($perPage, ['id'], 'page', $page);

        $ids = array_map(
            static fn ($row): int => (int) $row->id,
            $paginator->items(),
        );

        return [
            'ids' => $ids,
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => (int) $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ];
    }
}
