<?php

use App\Http\Controllers\FileController;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

class FilesFakeScoutBuilder
{
    public string $lastQuery = '';

    public array $wheres = [];

    public array $orders = [];

    public ?int $lastPerPage = null;

    public function __construct(private Collection $items) {}

    public function withQuery(string $query): self
    {
        $this->lastQuery = $query;

        return $this;
    }

    public function where(string $field, $value): self
    {
        $this->wheres[$field] = $value;

        return $this;
    }

    public function orderBy(string $field, string $direction): self
    {
        $this->orders[$field] = $direction;

        return $this;
    }

    public function paginate(int $perPage): LengthAwarePaginator
    {
        $this->lastPerPage = $perPage;

        $results = $this->applyFilters($this->items);

        if (($this->orders['created_at'] ?? 'desc') === 'asc') {
            $results = $results->sortBy('created_at')->values();
        } else {
            $results = $results->sortByDesc('created_at')->values();
        }

        $page = max(1, (int) request()->query('page', 1));

        $paginator = new LengthAwarePaginator(
            $results->forPage($page, $perPage)->values(),
            $results->count(),
            $perPage,
            $page,
            ['path' => url('/files')]
        );

        return $paginator->withQueryString();
    }

    protected function applyFilters(Collection $items): Collection
    {
        $filtered = $items;

        if (($this->wheres['blacklisted'] ?? null) === false) {
            $filtered = $filtered->filter(static fn (File $file) => $file->blacklisted_at === null);
        }

        if (array_key_exists('has_path', $this->wheres)) {
            $expected = (bool) $this->wheres['has_path'];
            $filtered = $filtered->filter(static fn (File $file) => (bool) $file->path === $expected);
        }

        return $filtered->values();
    }
}

it('applies filters and limit when listing files', function () {
    $user = User::factory()->create();

    $localFiles = File::factory()
        ->count(3)
        ->sequence(
            ['created_at' => now()->subMinutes(5), 'path' => storage_path('app/local-1.mp3')],
            ['created_at' => now()->subMinutes(4), 'path' => storage_path('app/local-2.mp3')],
            ['created_at' => now()->subMinutes(3), 'path' => storage_path('app/local-3.mp3')]
        )
        ->create([
            'blacklisted_at' => null,
            'url' => null,
        ]);

    File::factory()->create([
        'created_at' => now()->subMinutes(2),
        'path' => null,
        'url' => 'https://example.com/file.jpg',
    ]);

    File::factory()->create([
        'created_at' => now()->subMinutes(1),
        'path' => storage_path('app/ignored.mp3'),
        'blacklisted_at' => now(),
    ]);

    $builder = new FilesFakeScoutBuilder(File::query()->get());

    app()->bind(FileController::class, function () use ($builder) {
        return new class($builder) extends FileController
        {
            public function __construct(private FilesFakeScoutBuilder $builder) {}

            protected function newSearchBuilder(string $query)
            {
                return $this->builder->withQuery($query);
            }
        };
    });

    actingAs($user);

    $response = get('/files?q=%20sample%20&origin=local&sort=oldest&limit=40');

    $expectedOrder = $localFiles->sortBy('created_at')->pluck('id')->values()->all();

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('files/Index')
        ->where('filters.q', 'sample')
        ->where('filters.sort', 'oldest')
        ->where('filters.origin', 'local')
        ->where('filters.limit', 40)
        ->where('files.per_page', 40)
        ->where('files.total', count($expectedOrder))
        ->where('files.data', static function (array $items) use ($expectedOrder): bool {
            $ids = collect($items)->pluck('id')->values()->all();

            return $ids === $expectedOrder;
        })
    );

    expect($builder->lastQuery)->toBe('sample')
        ->and($builder->wheres)->toMatchArray([
            'blacklisted' => false,
            'has_path' => true,
        ])
        ->and($builder->orders['created_at'] ?? null)->toBe('asc')
        ->and($builder->lastPerPage)->toBe(40);
});

it('falls back to default limit for unsupported values', function () {
    $user = User::factory()->create();

    File::factory()->count(2)->create(['path' => storage_path('app/local.mp3'), 'blacklisted_at' => null]);

    $builder = new FilesFakeScoutBuilder(File::query()->get());

    app()->bind(FileController::class, function () use ($builder) {
        return new class($builder) extends FileController
        {
            public function __construct(private FilesFakeScoutBuilder $builder) {}

            protected function newSearchBuilder(string $query)
            {
                return $this->builder->withQuery($query);
            }
        };
    });

    actingAs($user);

    $response = get('/files?limit=13');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('files/Index')
        ->where('filters.limit', 20)
        ->where('files.per_page', 20)
    );

    expect($builder->lastPerPage)->toBe(20);
});
