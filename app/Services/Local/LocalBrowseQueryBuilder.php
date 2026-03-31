<?php

namespace App\Services\Local;

use App\Models\File;

class LocalBrowseQueryBuilder
{
    /**
     * Hydrate local browse hits in the exact order returned by Typesense.
     *
     * @param  array<int, int>  $ids
     * @return array<int, File>
     */
    public static function hydrateFiles(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $filesById = File::query()
            ->with('metadata')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return collect($ids)
            ->map(static fn (int $id): ?File => $filesById->get($id))
            ->filter()
            ->values()
            ->all();
    }
}
