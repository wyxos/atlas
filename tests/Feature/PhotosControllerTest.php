<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;

class FakeTypesenseEngine extends Engine
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

    public function search(Builder $builder): array
    {
        $limit = $builder->limit ?? count($this->documents);

        return $this->performSearch($builder, $limit, 1);
    }

    public function paginate(Builder $builder, $perPage, $page): array
    {
        return $this->performSearch($builder, (int) $perPage, (int) $page);
    }

    public function mapIds($results)
    {
        return collect($results['hits'] ?? [])
            ->pluck('document.id')
            ->values();
    }

    public function map(Builder $builder, $results, $model)
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

    public function lazyMap(Builder $builder, $results, $model): LazyCollection
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

    protected function performSearch(Builder $builder, int $perPage, int $page): array
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

beforeEach(function () {
    $this->originalScoutDriver = config('scout.driver');

    $this->fakeTypesense = new FakeTypesenseEngine;

    resolve(EngineManager::class)->extend('fake-typesense', function () {
        return $this->fakeTypesense;
    });

    config()->set('scout.driver', 'fake-typesense');
});

afterEach(function () {
    config()->set('scout.driver', $this->originalScoutDriver);
});

it('returns the newest files excluding disliked files when sorted by newest', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $seededAt = Carbon::now();

    $files = collect();
    $documents = [];

    for ($index = 0; $index < 25; $index++) {
        /** @var File $file */
        $file = File::factory()->create([
            'mime_type' => 'image/jpeg',
            'path' => 'local/path-'.$index,
            'downloaded_at' => $seededAt->copy()->subMinutes($index),
        ]);

        if ($index < 5) {
            Reaction::factory()->create([
                'file_id' => $file->id,
                'user_id' => $user->id,
                'type' => 'dislike',
            ]);
        }

        $files->push($file->fresh());

        $documents[] = [
            'id' => (string) $file->id,
            'mime_group' => 'image',
            'source' => 'local',
            'has_reactions' => $index < 5,
            'downloaded_at' => $file->downloaded_at?->timestamp ?? 0,
            'created_at' => $file->created_at?->timestamp ?? 0,
            'dislike_user_ids' => $index < 5 ? [(string) $user->id] : [],
        ];
    }

    $this->fakeTypesense->setDocuments($documents);

    $response = $this->getJson(route('photos.data', [
        'sort' => 'newest',
        'limit' => 20,
    ]));

    $response->assertOk();

    $payload = $response->json();
    $filesPayload = $payload['files'];

    expect($filesPayload)->toHaveCount(20);

    $dislikedIds = collect($documents)
        ->filter(fn ($doc) => in_array((string) $user->id, $doc['dislike_user_ids'], true))
        ->pluck('id')
        ->map(fn ($id) => (int) $id)
        ->all();

    $returnedIds = collect($filesPayload)->pluck('id')->map(fn ($id) => (int) $id)->all();

    expect($returnedIds)->each(fn ($id) => expect($dislikedIds)->not->toContain($id));

    $expectedOrder = collect($documents)
        ->reject(fn ($doc) => in_array((string) $user->id, $doc['dislike_user_ids'], true))
        ->sortByDesc('downloaded_at')
        ->take(20)
        ->pluck('id')
        ->map(fn ($id) => (int) $id)
        ->values()
        ->all();

    expect($returnedIds)->toEqual($expectedOrder);
});

it('returns 20 unique random files when sorted by random', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $seededAt = Carbon::now();

    $documents = [];

    for ($index = 0; $index < 25; $index++) {
        /** @var File $file */
        $file = File::factory()->create([
            'mime_type' => 'image/jpeg',
            'path' => 'local/path-'.$index,
            'downloaded_at' => $seededAt->copy()->subMinutes($index),
        ]);

        if ($index < 3) {
            Reaction::factory()->create([
                'file_id' => $file->id,
                'user_id' => $user->id,
                'type' => 'dislike',
            ]);
        }

        $documents[] = [
            'id' => (string) $file->id,
            'mime_group' => 'image',
            'source' => 'local',
            'has_reactions' => $index < 3,
            'downloaded_at' => $file->downloaded_at?->timestamp ?? 0,
            'created_at' => $file->created_at?->timestamp ?? 0,
            'dislike_user_ids' => $index < 3 ? [(string) $user->id] : [],
        ];
    }

    $this->fakeTypesense->setDocuments($documents);

    $seed = 1337;

    $response = $this->getJson(route('photos.data', [
        'sort' => 'random',
        'limit' => 20,
        'rand_seed' => $seed,
    ]));

    $response->assertOk();

    $payload = $response->json();
    $filesPayload = collect($payload['files']);

    expect($filesPayload)->toHaveCount(20);
    expect($filesPayload->pluck('id')->duplicates())->toBeEmpty();

    $returnedIds = $filesPayload->pluck('id')->map(fn ($id) => (int) $id)->values();

    $dislikedIds = collect($documents)
        ->filter(fn ($doc) => in_array((string) $user->id, $doc['dislike_user_ids'], true))
        ->pluck('id')
        ->map(fn ($id) => (int) $id)
        ->all();

    expect($returnedIds)->each(fn ($id) => expect($dislikedIds)->not->toContain($id));

    $expectedOrder = collect($documents)
        ->reject(fn ($doc) => in_array((string) $user->id, $doc['dislike_user_ids'], true))
        ->sortByDesc(fn ($doc) => crc32($seed.'|'.$doc['id']))
        ->take(20)
        ->pluck('id')
        ->map(fn ($id) => (int) $id)
        ->values();

    expect($returnedIds->all())->toEqual($expectedOrder->all());
    expect($payload['filter']['rand_seed'])->toBe($seed);
});
