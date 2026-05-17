<?php

namespace App\Services;

use App\Support\ServiceFilterSchema;

class WallhavenQueryParameters
{
    public function format(array $params): array
    {
        $page = isset($params['page']) ? (int) $params['page'] : 1;
        $page = max(1, $page);

        $query = [
            'page' => $page,
        ];

        $sorting = $this->resolveSorting($params['sort'] ?? null);
        $query['sorting'] = $sorting;

        $order = $this->resolveOrder($params['order'] ?? null);
        if ($order !== null) {
            $query['order'] = $order;
        }

        $q = $this->resolveText($params['q'] ?? null);
        if ($q !== null) {
            $query['q'] = $q;
        }

        $categories = $this->resolveCategories($params['categories'] ?? null);
        if ($categories !== null) {
            $query['categories'] = $categories;
        }

        // Handle NSFW/purity filter
        $purity = '100'; // Default: SFW only
        if (isset($params['nsfw'])) {
            $nsfw = $params['nsfw'];
            if ($nsfw === 1 || $nsfw === '1' || $nsfw === true) {
                $purity = '111'; // All content
            } elseif ($nsfw === 'soft' || $nsfw === 'mature') {
                $purity = '110'; // SFW + Sketchy
            }
        }
        $query['purity'] = $purity;

        if ($sorting === 'toplist') {
            $topRange = $this->resolveTopRange($params['topRange'] ?? null);
            if ($topRange !== null) {
                $query['topRange'] = $topRange;
            }
        }

        $atleast = $this->resolveResolution($params['atleast'] ?? null);
        if ($atleast !== null) {
            $query['atleast'] = $atleast;
        }

        $resolutions = $this->resolveResolutionList($params['resolutions'] ?? null);
        if ($resolutions !== null) {
            $query['resolutions'] = $resolutions;
        }

        $ratios = $this->resolveRatioList($params['ratios'] ?? null);
        if ($ratios !== null) {
            $query['ratios'] = $ratios;
        }

        $seed = $this->resolveSeed($params['seed'] ?? null);
        if ($seed !== null) {
            $query['seed'] = $seed;
        }

        // Add API key if configured
        if (config('services.wallhaven.key')) {
            $query['apikey'] = config('services.wallhaven.key');
        }

        return $query;
    }

    public function defaults(): array
    {
        return [
            'nsfw' => 0,
            'sort' => 'date_added',
            'order' => 'desc',
            // Wallhaven defaults to all categories enabled.
            'categories' => ['general', 'anime', 'people'],
            // Only used when sorting is 'toplist'.
            'topRange' => '1M',
        ];
    }

    public function schema(): array
    {
        $schema = ServiceFilterSchema::make()
            ->keys($this->schemaKeyMap())
            ->types($this->schemaTypeMap())
            ->labels($this->schemaLabelMap());

        return $schema->fields([
            ...$schema->paginationFields(),

            $schema->field('q', [
                'description' => 'Search query (supports tag operators like +tag, -tag, @user, id:123, like:ID).',
                'placeholder' => 'e.g. +nature -city 16:9',
            ]),
            $schema->field('categories', [
                'description' => 'Restrict categories (defaults to all).',
                'options' => [
                    ['label' => 'General', 'value' => 'general'],
                    ['label' => 'Anime', 'value' => 'anime'],
                    ['label' => 'People', 'value' => 'people'],
                ],
                'default' => ['general', 'anime', 'people'],
            ]),
            $schema->field('nsfw', [
                'description' => 'Content rating. NSFW requires a valid API key.',
                'options' => [
                    ['label' => 'SFW only', 'value' => 0],
                    ['label' => 'SFW + Sketchy', 'value' => 'soft'],
                    ['label' => 'SFW + Sketchy + NSFW', 'value' => 1],
                ],
                'default' => 0,
            ]),
            $schema->field('sort', [
                'description' => 'Method of sorting results.',
                'options' => [
                    ['label' => 'Date Added', 'value' => 'date_added'],
                    ['label' => 'Relevance', 'value' => 'relevance'],
                    ['label' => 'Random', 'value' => 'random'],
                    ['label' => 'Views', 'value' => 'views'],
                    ['label' => 'Favorites', 'value' => 'favorites'],
                    ['label' => 'Toplist', 'value' => 'toplist'],
                ],
                'default' => 'date_added',
            ]),
            $schema->field('order', [
                'description' => 'Sorting order.',
                'options' => [
                    ['label' => 'Descending', 'value' => 'desc'],
                    ['label' => 'Ascending', 'value' => 'asc'],
                ],
                'default' => 'desc',
            ]),
            $schema->field('topRange', [
                'description' => "Time window (only used when sorting is set to 'toplist').",
                'options' => [
                    ['label' => '1 Day', 'value' => '1d'],
                    ['label' => '3 Days', 'value' => '3d'],
                    ['label' => '1 Week', 'value' => '1w'],
                    ['label' => '1 Month', 'value' => '1M'],
                    ['label' => '3 Months', 'value' => '3M'],
                    ['label' => '6 Months', 'value' => '6M'],
                    ['label' => '1 Year', 'value' => '1y'],
                ],
                'default' => '1M',
            ]),
            $schema->field('atleast', [
                'description' => 'Minimum resolution (e.g. 1920x1080).',
                'placeholder' => '1920x1080',
            ]),
            $schema->field('resolutions', [
                'description' => 'Exact resolution(s), comma-separated (e.g. 1920x1080,1920x1200).',
                'placeholder' => '1920x1080',
            ]),
            $schema->field('ratios', [
                'description' => 'Aspect ratio(s), comma-separated (e.g. 16x9,16x10).',
                'placeholder' => '16x9',
            ]),
            $schema->field('seed', [
                'description' => 'Optional seed for random results (6 alphanumeric chars).',
                'placeholder' => 'a1B2c3',
            ]),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function schemaKeyMap(): array
    {
        return [
            'sort' => 'sorting',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function schemaTypeMap(): array
    {
        return [
            'page' => 'hidden',
            'limit' => 'number',
            'q' => 'text',
            'categories' => 'checkbox-group',
            'nsfw' => 'radio',
            'sort' => 'select',
            'order' => 'select',
            'topRange' => 'select',
            'atleast' => 'text',
            'resolutions' => 'text',
            'ratios' => 'text',
            'seed' => 'text',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function schemaLabelMap(): array
    {
        return [
            'q' => 'Query',
            'nsfw' => 'Purity',
            'sort' => 'Sorting',
            'topRange' => 'Toplist Range',
        ];
    }

    private function resolveText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function resolveSorting(mixed $value): string
    {
        if (! is_string($value)) {
            return 'date_added';
        }

        $normalized = strtolower(trim($value));

        $allowed = [
            'date_added',
            'relevance',
            'random',
            'views',
            'favorites',
            'toplist',
        ];

        return in_array($normalized, $allowed, true) ? $normalized : 'date_added';
    }

    private function resolveOrder(mixed $value): ?string
    {
        if (! is_string($value)) {
            return 'desc';
        }

        $normalized = strtolower(trim($value));

        return in_array($normalized, ['asc', 'desc'], true) ? $normalized : 'desc';
    }

    private function resolveTopRange(mixed $value): ?string
    {
        if (! is_string($value)) {
            return '1M';
        }

        $normalized = trim($value);

        $allowed = ['1d', '3d', '1w', '1M', '3M', '6M', '1y'];

        return in_array($normalized, $allowed, true) ? $normalized : '1M';
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringArray(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $v) {
            if (! is_string($v)) {
                continue;
            }
            $t = strtolower(trim($v));
            if ($t === '') {
                continue;
            }
            $out[] = $t;
        }

        return array_values(array_unique($out));
    }

    private function resolveCategories(mixed $value): ?string
    {
        $selected = $this->normalizeStringArray($value);
        if (count($selected) === 0) {
            return '111';
        }

        $flags = [
            'general' => '0',
            'anime' => '0',
            'people' => '0',
        ];

        foreach ($selected as $v) {
            if (array_key_exists($v, $flags)) {
                $flags[$v] = '1';
            }
        }

        return $flags['general'].$flags['anime'].$flags['people'];
    }

    private function resolveResolution(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        return preg_match('/^\d{2,5}x\d{2,5}$/', $normalized) ? $normalized : null;
    }

    private function resolveResolutionList(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $normalized)), fn ($p) => $p !== ''));
        if (count($parts) === 0) {
            return null;
        }

        $valid = [];
        foreach ($parts as $p) {
            if (preg_match('/^\d{2,5}x\d{2,5}$/', $p)) {
                $valid[] = $p;
            }
        }

        return count($valid) > 0 ? implode(',', $valid) : null;
    }

    private function resolveRatioList(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $normalized)), fn ($p) => $p !== ''));
        if (count($parts) === 0) {
            return null;
        }

        $valid = [];
        foreach ($parts as $p) {
            if (preg_match('/^\d{1,3}x\d{1,3}$/', $p)) {
                $valid[] = $p;
            }
        }

        return count($valid) > 0 ? implode(',', $valid) : null;
    }

    private function resolveSeed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        return preg_match('/^[a-zA-Z0-9]{6}$/', $normalized) ? $normalized : null;
    }
}
