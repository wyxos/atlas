<?php

use App\Models\File;
use App\Models\FileMetadata;
use App\Models\User;
use App\Services\BaseService;
use App\Services\Plugin\PluginServiceLoader;
use App\Services\Plugin\ServiceRegistry;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Config;
use Laravel\Scout\Builder;

class FakeScoutBuilder extends Builder
{
    protected array $fakeItems = [];

    public $orders = [];

    public function setFakeItems(array $items): self
    {
        $this->fakeItems = $items;

        return $this;
    }

    public function orderBy($column, $direction = 'asc')
    {
        $this->orders[] = [
            'column' => $column,
            'direction' => strtolower((string) $direction) === 'desc' ? 'desc' : 'asc',
        ];

        return $this;
    }

    public function paginate($perPage = null, $pageName = 'page', $page = null): LengthAwarePaginator
    {
        $perPage = $perPage ?? max(1, count($this->fakeItems));
        $page = $page ?? Paginator::resolveCurrentPage($pageName);

        $items = collect($this->fakeItems);

        foreach (array_reverse($this->orders) as $order) {
            $items = $items->sortBy(
                fn ($item) => data_get($item, $order['column']),
                SORT_REGULAR,
                $order['direction'] === 'desc'
            )->values();
        }

        $sorted = $items->all();

        return new LengthAwarePaginator(
            $sorted,
            count($sorted),
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]
        );
    }
}

if (! function_exists('withFakeScoutResults')) {
    /**
     * Temporarily bind Scout's Builder to return the provided items.
     *
     * @param  array<int, mixed>  $items
     */
    function withFakeScoutResults(array $items, callable $callback): void
    {
        $container = app();
        $wasBound = $container->bound(Builder::class);
        $previous = $wasBound ? $container->getBindings()[Builder::class] : null;

        $container->bind(Builder::class, function ($app, $params) use ($items) {
            $builder = new FakeScoutBuilder(
                $params['model'],
                $params['query'],
                $params['callback'] ?? null,
                $params['softDelete'] ?? false
            );

            return $builder->setFakeItems($items);
        });

        try {
            $callback();
        } finally {
            $container->forgetInstance(Builder::class);
            $container->forgetScopedInstances();

            if ($wasBound && $previous) {
                if ($previous['shared']) {
                    $container->singleton(Builder::class, $previous['concrete']);
                } else {
                    $container->bind(Builder::class, $previous['concrete']);
                }
            } else {
                unset($container[Builder::class]);
            }
        }
    }
}

it('includes prompt and moderation metadata in photos payload', function () {
    Config::set('scout.driver', 'collection');
    Config::set('scout.queue', false);
    Config::set('scout.after_commit', false);

    $user = User::factory()->create();
    $this->actingAs($user);

    $file = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'path' => 'photos/example.jpg',
        'thumbnail_url' => 'https://example.com/thumb.jpg',
        'listing_metadata' => [
            'meta' => [
                'prompt' => 'Fallback prompt value',
            ],
        ],
    ]);

    FileMetadata::factory()->for($file)->create([
        'payload' => [
            'prompt' => 'Scenic view of the mountains',
            'moderation' => [
                'reason' => 'moderation:rule',
                'rule_id' => 42,
                'rule_name' => 'Manual Fixture',
                'hits' => ['mountains'],
            ],
            'width' => 1024,
            'height' => 768,
        ],
    ]);

    $file->searchable();

    $response = null;

    withFakeScoutResults([$file], function () use (&$response) {
        $response = $this->getJson(route('photos.data', ['limit' => 10]));
        $response->assertStatus(200);
    });

    $payload = $response->json('files.0');
    expect($payload)->not->toBeNull();
    expect($payload['metadata']['prompt'] ?? null)->toEqual('Scenic view of the mountains');
    expect($payload['metadata']['moderation']['reason'] ?? null)->toEqual('moderation:rule');
    expect($payload['detail_metadata']['width'] ?? null)->toEqual(1024);
    expect($payload['detail_metadata']['height'] ?? null)->toEqual(768);
});

it('decorates remote originals in photos payload', function () {
    Config::set('scout.driver', 'collection');
    Config::set('scout.queue', false);
    Config::set('scout.after_commit', false);

    $user = User::factory()->create();
    $this->actingAs($user);

    $file = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'path' => null,
        'url' => 'https://cdn.example.com/files/image.jpg',
        'thumbnail_url' => 'https://cdn.example.com/files/thumb.jpg',
        'source' => 'DecoratedSource',
    ]);

    $file->searchable();

    $registry = new ServiceRegistry;
    app()->instance(ServiceRegistry::class, $registry);
    app()->instance(\Atlas\Plugin\Contracts\ServiceRegistry::class, $registry);

    $this->mock(PluginServiceLoader::class, function ($mock) {
        $mock->shouldReceive('load')->atLeast()->once();
    });

    $service = new class extends BaseService
    {
        public const KEY = 'decorated-service';

        public const SOURCE = 'DecoratedSource';

        public const LABEL = 'Decorated Service';

        public function defaultParams(): array
        {
            return [];
        }

        public function fetch(array $params = []): array
        {
            return [];
        }

        public function transform(array $response, array $params = []): array
        {
            return ['files' => [], 'filter' => []];
        }

        public function decorateOriginalUrl(File $file, string $originalUrl, ?\Illuminate\Contracts\Auth\Authenticatable $viewer = null): string
        {
            return $originalUrl.'?token=secret';
        }
    };

    $registry->register($service);

    $response = null;

    withFakeScoutResults([$file], function () use (&$response) {
        $response = $this->getJson(route('photos.data', ['limit' => 10]));
        $response->assertStatus(200);
    });

    expect($response->json('files.0.original'))->toBe('https://cdn.example.com/files/image.jpg?token=secret');
});

it('decorates remote originals in disliked feed payload', function () {
    Config::set('scout.driver', 'collection');
    Config::set('scout.queue', false);
    Config::set('scout.after_commit', false);

    $user = User::factory()->create();
    $this->actingAs($user);

    $file = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'path' => null,
        'url' => 'https://cdn.example.com/files/disliked.jpg',
        'thumbnail_url' => 'https://cdn.example.com/files/disliked-thumb.jpg',
        'source' => 'DecoratedSource',
        'blacklisted_at' => now(),
        'blacklist_reason' => 'disliked',
    ]);

    $file->searchable();

    $registry = new ServiceRegistry;
    app()->instance(ServiceRegistry::class, $registry);
    app()->instance(\Atlas\Plugin\Contracts\ServiceRegistry::class, $registry);

    $this->mock(PluginServiceLoader::class, function ($mock) {
        $mock->shouldReceive('load')->atLeast()->once();
    });

    $service = new class extends BaseService
    {
        public const KEY = 'decorated-service';

        public const SOURCE = 'DecoratedSource';

        public const LABEL = 'Decorated Service';

        public function defaultParams(): array
        {
            return [];
        }

        public function fetch(array $params = []): array
        {
            return [];
        }

        public function transform(array $response, array $params = []): array
        {
            return ['files' => [], 'filter' => []];
        }

        public function decorateOriginalUrl(File $file, string $originalUrl, ?\Illuminate\Contracts\Auth\Authenticatable $viewer = null): string
        {
            return $originalUrl.'?token=secret';
        }
    };

    $registry->register($service);

    $response = null;

    withFakeScoutResults([$file], function () use (&$response) {
        $response = $this->getJson(route('photos.disliked.data', ['category' => 'all', 'limit' => 10]));
        $response->assertStatus(200);
    });

    expect($response->json('files.0.original'))->toBe('https://cdn.example.com/files/disliked.jpg?token=secret');
});

it('decorates remote originals in unrated feed payload', function () {
    Config::set('scout.driver', 'collection');
    Config::set('scout.queue', false);
    Config::set('scout.after_commit', false);

    $user = User::factory()->create();
    $this->actingAs($user);

    $file = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'path' => null,
        'url' => 'https://cdn.example.com/files/unrated.jpg',
        'thumbnail_url' => 'https://cdn.example.com/files/unrated-thumb.jpg',
        'source' => 'DecoratedSource',
        'blacklisted_at' => null,
    ]);

    $file->searchable();

    $registry = new ServiceRegistry;
    app()->instance(ServiceRegistry::class, $registry);
    app()->instance(\Atlas\Plugin\Contracts\ServiceRegistry::class, $registry);

    $this->mock(PluginServiceLoader::class, function ($mock) {
        $mock->shouldReceive('load')->atLeast()->once();
    });

    $service = new class extends BaseService
    {
        public const KEY = 'decorated-service';

        public const SOURCE = 'DecoratedSource';

        public const LABEL = 'Decorated Service';

        public function defaultParams(): array
        {
            return [];
        }

        public function fetch(array $params = []): array
        {
            return [];
        }

        public function transform(array $response, array $params = []): array
        {
            return ['files' => [], 'filter' => []];
        }

        public function decorateOriginalUrl(File $file, string $originalUrl, ?\Illuminate\Contracts\Auth\Authenticatable $viewer = null): string
        {
            return $originalUrl.'?token=secret';
        }
    };

    $registry->register($service);

    $response = null;

    withFakeScoutResults([$file], function () use (&$response) {
        $response = $this->getJson(route('photos.unrated.data', ['limit' => 10]));
        $response->assertStatus(200);
    });

    expect($response->json('files.0.original'))->toBe('https://cdn.example.com/files/unrated.jpg?token=secret');
});
