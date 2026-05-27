<?php

use App\Exceptions\LibraryUnavailableException;
use App\Models\File;
use App\Models\User;
use App\Services\FilePreviewService;
use App\Services\Library\LibraryTypesenseCompiler;
use App\Services\Library\LibraryTypesenseGateway;
use App\Services\Library\LibraryTypesenseNames;
use App\Services\LocalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fakeLibraryNames(bool $hasFilesAlias = true, bool $hasReactionsAlias = true): LibraryTypesenseNames
{
    $names = \Mockery::mock(LibraryTypesenseNames::class);
    $names->shouldReceive('hasFilesAlias')->andReturn($hasFilesAlias);
    $names->shouldReceive('hasReactionsAlias')->andReturn($hasReactionsAlias);
    $names->shouldReceive('currentReactionJoinCollection')->andReturn('atlas_local_library_files__vtest');
    $names->shouldReceive('filesAlias')->andReturn('atlas_local_library_files');
    $names->shouldReceive('reactionsAlias')->andReturn('atlas_local_library_reactions');

    return $names;
}

test('local service hydrates typesense file hits in returned order and uses found as total', function () {
    $older = File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subDay(),
        'blacklisted_at' => null,
        'auto_blacklisted' => false,
    ]);
    $newer = File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subHour(),
        'blacklisted_at' => null,
        'auto_blacklisted' => false,
    ]);

    $names = fakeLibraryNames();
    app()->instance(LibraryTypesenseNames::class, $names);

    $gateway = new class(app(LibraryTypesenseCompiler::class), $names, ['hits' => [['document' => ['id' => (string) $newer->id]], ['document' => ['id' => (string) $older->id]]], 'found' => 42, 'out_of' => 999, 'search_time_ms' => 1]) extends LibraryTypesenseGateway
    {
        public array $compiled = [];

        /**
         * @param  array<string, mixed>  $results
         */
        public function __construct($compiler, $names, private array $results)
        {
            parent::__construct($compiler, $names);
        }

        protected function runScoutSearch(array $compiled): array
        {
            $this->compiled[] = $compiled;

            return $this->results;
        }
    };
    app()->instance(LibraryTypesenseGateway::class, $gateway);

    $result = app(LocalService::class)->fetch([
        'page' => 1,
        'limit' => 2,
    ]);

    expect(collect($result['files'])->pluck('id')->all())->toBe([$newer->id, $older->id])
        ->and($result['metadata']['nextCursor'])->toBe(2)
        ->and($result['metadata']['total'])->toBe(42);
});

test('local service uses scout paginated out_of as total at max page size', function () {
    $names = fakeLibraryNames();
    app()->instance(LibraryTypesenseNames::class, $names);

    $hits = array_map(
        fn (int $id): array => ['document' => ['id' => (string) $id]],
        range(1, 250),
    );

    $gateway = new class(app(LibraryTypesenseCompiler::class), $names, ['hits' => $hits, 'found' => 250, 'out_of' => 20391, 'page' => 1, 'request_params' => ['per_page' => 250]]) extends LibraryTypesenseGateway
    {
        /**
         * @param  array<string, mixed>  $results
         */
        public function __construct($compiler, $names, private array $results)
        {
            parent::__construct($compiler, $names);
        }

        protected function runScoutSearch(array $compiled): array
        {
            return $this->results;
        }
    };
    app()->instance(LibraryTypesenseGateway::class, $gateway);

    $result = app(LocalService::class)->fetch([
        'page' => 1,
        'limit' => 250,
    ]);

    expect($result['metadata']['nextCursor'])->toBe(2)
        ->and($result['metadata']['total'])->toBe(20391);
});

test('local service hydrates reaction timestamp hits from file ids', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $older = File::factory()->create([
        'downloaded' => true,
        'blacklisted_at' => null,
        'auto_blacklisted' => false,
    ]);
    $newer = File::factory()->create([
        'downloaded' => true,
        'blacklisted_at' => null,
        'auto_blacklisted' => false,
    ]);

    $names = fakeLibraryNames();
    app()->instance(LibraryTypesenseNames::class, $names);

    $gateway = new class(app(LibraryTypesenseCompiler::class), $names, ['hits' => [['document' => ['file_id' => (string) $newer->id]], ['document' => ['file_id' => (string) $older->id]]], 'found' => 2]) extends LibraryTypesenseGateway
    {
        public array $compiled = [];

        /**
         * @param  array<string, mixed>  $results
         */
        public function __construct($compiler, $names, private array $results)
        {
            parent::__construct($compiler, $names);
        }

        protected function runScoutSearch(array $compiled): array
        {
            $this->compiled[] = $compiled;

            return $this->results;
        }
    };
    app()->instance(LibraryTypesenseGateway::class, $gateway);

    $result = app(LocalService::class)->fetch([
        'page' => 1,
        'limit' => 2,
        'sort' => 'reaction_at',
        'reaction_mode' => 'reacted',
    ]);

    expect(collect($result['files'])->pluck('id')->all())->toBe([$newer->id, $older->id])
        ->and($result['metadata']['nextCursor'])->toBeNull()
        ->and($result['metadata']['total'])->toBe(2);
});

test('local service keeps seeded random sort when using typesense', function () {
    File::factory()->create([
        'downloaded' => true,
        'blacklisted_at' => null,
        'auto_blacklisted' => false,
    ]);

    $names = fakeLibraryNames();
    app()->instance(LibraryTypesenseNames::class, $names);

    $gateway = new class(app(LibraryTypesenseCompiler::class), $names, ['hits' => [], 'found' => 0]) extends LibraryTypesenseGateway
    {
        public array $compiled = [];

        /**
         * @param  array<string, mixed>  $results
         */
        public function __construct($compiler, $names, private array $results)
        {
            parent::__construct($compiler, $names);
        }

        protected function runScoutSearch(array $compiled): array
        {
            $this->compiled[] = $compiled;

            return $this->results;
        }
    };

    app()->instance(LibraryTypesenseGateway::class, $gateway);

    app(LocalService::class)->fetch([
        'page' => 1,
        'limit' => 10,
        'sort' => 'random',
        'seed' => 12345,
    ]);

    expect($gateway->compiled)->toHaveCount(1)
        ->and($gateway->compiled[0]['options']['sort_by'])->toBe('_rand(12345):desc,sort_id:desc');
});

test('local service compiles out-of-feed filters with updated timestamp sort', function () {
    $names = fakeLibraryNames();
    app()->instance(LibraryTypesenseNames::class, $names);

    $gateway = new class(app(LibraryTypesenseCompiler::class), $names, ['hits' => [], 'found' => 0]) extends LibraryTypesenseGateway
    {
        public array $compiled = [];

        /**
         * @param  array<string, mixed>  $results
         */
        public function __construct($compiler, $names, private array $results)
        {
            parent::__construct($compiler, $names);
        }

        protected function runScoutSearch(array $compiled): array
        {
            $this->compiled[] = $compiled;

            return $this->results;
        }
    };

    app()->instance(LibraryTypesenseGateway::class, $gateway);

    app(LocalService::class)->fetch([
        'page' => 1,
        'limit' => 20,
        'blacklisted' => 'yes',
        'min_previewed_count' => FilePreviewService::FEED_REMOVED_PREVIEW_COUNT,
        'sort' => 'updated_at',
    ]);

    app(LocalService::class)->fetch([
        'page' => 1,
        'limit' => 20,
        'blacklisted' => 'yes',
        'min_previewed_count' => FilePreviewService::FEED_REMOVED_PREVIEW_COUNT,
        'sort' => 'updated_at_asc',
    ]);

    expect($gateway->compiled)->toHaveCount(2)
        ->and($gateway->compiled[0]['options']['filter_by'])->toContain('blacklisted:=true')
        ->and($gateway->compiled[0]['options']['filter_by'])->toContain('previewed_count:>='.FilePreviewService::FEED_REMOVED_PREVIEW_COUNT)
        ->and($gateway->compiled[0]['options']['sort_by'])->toBe('updated_at:desc,sort_id:desc')
        ->and($gateway->compiled[1]['options']['sort_by'])->toBe('updated_at:asc,sort_id:asc');
});

test('local service throws when library aliases are missing', function () {
    $names = fakeLibraryNames(hasFilesAlias: false);
    app()->instance(LibraryTypesenseNames::class, $names);
    app()->instance(LibraryTypesenseGateway::class, new LibraryTypesenseGateway(
        app(LibraryTypesenseCompiler::class),
        $names,
    ));

    expect(fn () => app(LocalService::class)->fetch([
        'page' => 1,
        'limit' => 20,
    ]))->toThrow(LibraryUnavailableException::class);
});
