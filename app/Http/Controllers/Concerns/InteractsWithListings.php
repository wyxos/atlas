<?php

namespace App\Http\Controllers\Concerns;

use App\Models\File;
use App\Models\Reaction;
use App\Support\ListingOptions;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator as PaginatorContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

trait InteractsWithListings
{
    /**
     * @param  array{allowed_sorts?:array<int, string>, default_sort?:string, fallback_sort?:string, default_limit?:int, max_limit?:int, page_name?:string}  $options
     */
    protected function resolveListingOptions(array $options = []): ListingOptions
    {
        $defaultLimit = (int) ($options['default_limit'] ?? 20);
        $maxLimit = (int) ($options['max_limit'] ?? 200);
        $pageName = (string) ($options['page_name'] ?? 'page');

        $limit = $this->boundedLimit((int) request('limit', $defaultLimit), $maxLimit);
        $page = max(1, (int) request($pageName, 1));

        $defaultSort = strtolower((string) ($options['default_sort'] ?? 'newest'));
        $fallbackSort = strtolower((string) ($options['fallback_sort'] ?? $defaultSort));

        $allowedSorts = $options['allowed_sorts'] ?? [$defaultSort];
        $allowedSorts = array_map(static fn ($value) => strtolower((string) $value), $allowedSorts);

        if (! in_array($fallbackSort, $allowedSorts, true)) {
            $allowedSorts[] = $fallbackSort;
        }

        $sort = strtolower((string) request('sort', $defaultSort));
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = $fallbackSort;
        }

        $requestedRandSeed = request('rand_seed');
        $randSeed = null;

        if ($sort === 'random') {
            $randSeed = $this->resolveRandomSeed($requestedRandSeed);
        }

        return new ListingOptions($limit, $page, $sort, $randSeed, $requestedRandSeed, $pageName);
    }

    protected function resolveRandomSeed(mixed $seed): int
    {
        if (! is_numeric($seed) || (int) $seed <= 0) {
            try {
                return random_int(1, 2147483646);
            } catch (\Throwable $exception) {
                return mt_rand(1, 2147483646);
            }
        }

        return (int) $seed;
    }

    protected function boundedLimit(int $value, int $max): int
    {
        return max(1, min($max, $value));
    }

    /**
     * @return array<int>
     */
    protected function extractIdsFromPaginator(LengthAwarePaginator|PaginatorContract $paginator): array
    {
        return collect($paginator->items() ?? [])
            ->map(static fn ($item) => (int) ($item['id'] ?? $item->id ?? 0))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int>  $ids
     */
    protected function loadFilesByIds(array $ids, array $relations = ['metadata']): Collection
    {
        if (empty($ids)) {
            return collect();
        }

        return File::with($relations)->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * @param  array<int>  $ids
     * @return array<int, string>
     */
    protected function reactionsForUser(array $ids, ?int $userId): array
    {
        if (! $userId || empty($ids)) {
            return [];
        }

        return Reaction::query()
            ->whereIn('file_id', $ids)
            ->where('user_id', $userId)
            ->pluck('type', 'file_id')
            ->toArray();
    }

    protected function currentUserId(): ?int
    {
        return auth()->id();
    }

    protected function normalizeSource(?string $source): ?string
    {
        if (! is_string($source)) {
            return null;
        }

        $source = trim($source);

        if ($source === '' || in_array($source, ['null', 'undefined', 'all'], true)) {
            return null;
        }

        return $source;
    }

    protected function requestedMimeType(): ?string
    {
        $value = request('mime_type');

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || in_array($value, ['null', 'undefined'], true)) {
            return null;
        }

        return $value;
    }

    protected function requestedFileId(): ?int
    {
        $value = request('file_id');

        if ($value === null || $value === '') {
            return null;
        }

        $id = is_numeric($value) ? (int) $value : null;

        return $id && $id > 0 ? $id : null;
    }

    protected function requestedSourceId(): ?string
    {
        $value = request('source_id');

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || in_array($value, ['null', 'undefined'], true)) {
            return null;
        }

        return $value;
    }

    /**
     * Apply sorting to a Scout query based on ListingOptions.
     *
     * @param  \Laravel\Scout\Builder  $query
     * @param  string|null  $primaryField  Primary field for newest/oldest sorting (default: 'downloaded_at')
     * @param  string|null  $secondaryField  Secondary field for tie-breaking (default: 'created_at', null to skip)
     */
    protected function applySorting($query, ListingOptions $options, ?string $primaryField = 'downloaded_at', ?string $secondaryField = 'created_at'): void
    {
        if ($options->sort === 'random') {
            $query->orderBy('_rand('.$options->randSeed.')', 'desc');

            return;
        }

        $direction = $options->sort === 'oldest' ? 'asc' : 'desc';

        if ($primaryField) {
            $query->orderBy($primaryField, $direction);
        }

        if ($secondaryField) {
            $query->orderBy($secondaryField, $direction);
        }
    }

    /**
     * Derive the data route URL from the current route context.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function deriveDataRouteUrl(array $parameters = []): string
    {
        $route = request()->route();

        if (! $route) {
            throw new \RuntimeException('Unable to determine current route');
        }

        $routeName = $route->getName();

        if (! $routeName) {
            throw new \RuntimeException('Current route has no name');
        }

        // If already ends with .data, use it as-is
        if (str_ends_with($routeName, '.data')) {
            $dataRouteName = $routeName;
        } elseif (str_ends_with($routeName, '.index')) {
            // Remove .index and append .data
            $dataRouteName = substr($routeName, 0, -6).'.data';
        } else {
            // Append .data
            $dataRouteName = $routeName.'.data';
        }

        // Merge route parameters with provided parameters
        $allParameters = array_merge($route->parameters(), $parameters);

        return route($dataRouteName, $allParameters);
    }

    /**
     * Build the standard filter payload returned to Inertia responses.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function buildListingFilter(ListingOptions $options, ?LengthAwarePaginator $paginator, array $overrides = []): array
    {
        $base = [
            'page' => $options->page,
            'next' => $paginator && $paginator->hasMorePages() ? ($options->page + 1) : null,
            'limit' => $options->limit,
            'data_url' => $this->deriveDataRouteUrl(),
            'total' => $paginator && method_exists($paginator, 'total') ? (int) $paginator->total() : null,
            'sort' => $options->sort,
            'rand_seed' => $options->isRandom() ? $options->randSeed : null,
            'source' => $this->normalizeSource(request('source')),
            'mime_type' => $this->requestedMimeType(),
            'file_id' => $this->requestedFileId(),
            'source_id' => $this->requestedSourceId(),
        ];

        return array_replace($base, $overrides);
    }

    /**
     * Get distinct source values for a given mime group (image or video) with caching.
     *
     * Optimized for large datasets (900k+ files) using GROUP BY and longer cache duration.
     *
     * @return array<string>
     */
    protected function getDistinctSources(string $mimeGroup): array
    {
        $cacheKey = "files.sources.{$mimeGroup}";

        // Cache for 1 hour since sources don't change frequently
        return Cache::remember($cacheKey, 3600, function () use ($mimeGroup) {
            $mimePrefix = match ($mimeGroup) {
                'image' => 'image/',
                'video' => 'video/',
                default => throw new \InvalidArgumentException("Invalid mime group: {$mimeGroup}"),
            };

            // Use GROUP BY instead of DISTINCT for better performance on large datasets
            // The mime_type index will be used for the WHERE clause
            return File::query()
                ->select('source')
                ->where('mime_type', 'like', "{$mimePrefix}%")
                ->groupBy('source')
                ->orderBy('source')
                ->pluck('source')
                ->filter()
                ->values()
                ->toArray();
        });
    }
}
