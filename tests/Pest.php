<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Shared Test Classes
|--------------------------------------------------------------------------
|
| Shared classes used across multiple test files.
|
*/

if (! class_exists('FakeTypesenseEngine')) {
    class FakeTypesenseEngine extends \Laravel\Scout\Engines\Engine
    {
        /**
         * @var array<int, array<string, mixed>>
         */
        protected array $documents = [];

        public function setDocuments(array $documents): void
        {
            $this->documents = array_values($documents);
        }

        public function update($models): void {}

        public function delete($models): void {}

        public function search(\Laravel\Scout\Builder $builder): array
        {
            $limit = $builder->limit ?? count($this->documents);

            return $this->performSearch($builder, $limit, 1);
        }

        public function paginate(\Laravel\Scout\Builder $builder, $perPage, $page): array
        {
            return $this->performSearch($builder, (int) $perPage, (int) $page);
        }

        public function mapIds($results)
        {
            return collect($results['hits'] ?? [])
                ->pluck('document.id')
                ->values();
        }

        public function map(\Laravel\Scout\Builder $builder, $results, $model)
        {
            $ids = collect($results['hits'] ?? [])
                ->pluck('document.id')
                ->map(static fn ($id) => (int) $id)
                ->values()
                ->all();

            if (empty($ids)) {
                return $model->newCollection();
            }

            return $model->getScoutModelsByIds($builder, $ids)
                ->filter(static fn ($instance) => in_array($instance->getScoutKey(), $ids, true))
                ->sortBy(static fn ($instance) => array_search($instance->getScoutKey(), $ids, true))
                ->values();
        }

        public function lazyMap(\Laravel\Scout\Builder $builder, $results, $model): \Illuminate\Support\LazyCollection
        {
            return $this->map($builder, $results, $model)->toLazyCollection();
        }

        public function getTotalCount($results): int
        {
            return (int) ($results['found'] ?? 0);
        }

        public function flush($model): void {}

        public function createIndex($name, array $options = []) {}

        public function deleteIndex($name) {}

        protected function performSearch(\Laravel\Scout\Builder $builder, int $perPage, int $page): array
        {
            $filtered = array_values(array_filter($this->documents, function (array $document) use ($builder) {
                foreach ($builder->wheres as $field => $value) {
                    if ($field === '__soft_deleted') {
                        continue;
                    }

                    if (! array_key_exists($field, $document)) {
                        return false;
                    }

                    if ($document[$field] !== $value) {
                        return false;
                    }
                }

                foreach ($builder->whereIns as $field => $values) {
                    $current = $document[$field] ?? null;

                    if (! in_array($current, $values, true)) {
                        return false;
                    }
                }

                foreach ($builder->whereNotIns as $field => $values) {
                    $current = $document[$field] ?? [];

                    $currentValues = is_array($current) ? $current : [$current];

                    if (! empty(array_intersect($values, $currentValues))) {
                        return false;
                    }
                }

                return true;
            }));

            if (! empty($builder->orders)) {
                usort($filtered, function (array $a, array $b) use ($builder) {
                    foreach ($builder->orders as $order) {
                        $direction = strtolower($order['direction'] ?? 'asc') === 'desc' ? -1 : 1;
                        $column = $order['column'];

                        $valueA = $this->resolveSortableValue($a, $column);
                        $valueB = $this->resolveSortableValue($b, $column);

                        if ($valueA === $valueB) {
                            continue;
                        }

                        return ($valueA <=> $valueB) * $direction;
                    }

                    return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
                });
            }

            $total = count($filtered);
            $offset = max(0, ($page - 1) * $perPage);
            $pageDocuments = array_slice($filtered, $offset, $perPage);

            $hits = array_map(static fn ($document) => ['document' => $document], $pageDocuments);

            return [
                'hits' => $hits,
                'found' => $total,
                'out_of' => $total,
                'page' => $page,
                'request_params' => [
                    'per_page' => $perPage,
                    'page' => $page,
                ],
            ];
        }

        protected function resolveSortableValue(array $document, string $column): int
        {
            if (preg_match('/^_rand\((\d+)\)$/', $column, $matches)) {
                $seed = (int) $matches[1];

                return $this->deterministicRandomValue($document, $seed);
            }

            return (int) ($document[$column] ?? 0);
        }

        protected function deterministicRandomValue(array $document, int $seed): int
        {
            $id = (string) ($document['id'] ?? '0');

            return crc32($seed.'|'.$id);
        }
    }
}
