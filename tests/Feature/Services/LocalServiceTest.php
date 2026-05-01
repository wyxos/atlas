<?php

use App\Models\File;
use App\Services\Local\LocalBrowseTypesenseGateway;
use App\Services\LocalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(LocalService::class);
});

test('returns correct key source and label', function () {
    expect(LocalService::key())->toBe('local');
    expect(LocalService::source())->toBe('Local');
    expect(LocalService::label())->toBe('Local Files');
});

test('fetch delegates normalized params to the typesense gateway', function () {
    $file = File::factory()->create();

    mock(LocalBrowseTypesenseGateway::class)
        ->shouldReceive('search')
        ->once()
        ->withArgs(function (array $context): bool {
            expect($context['page'])->toBe(2)
                ->and($context['limit'])->toBe(15)
                ->and($context['source'])->toBe('Wallhaven')
                ->and($context['downloaded'])->toBe('no')
                ->and($context['fileTypes'])->toBe(['video'])
                ->and($context['reactionMode'])->toBe('types')
                ->and($context['reactionTypes'])->toBe(['funny'])
                ->and($context['sort'])->toBe('reaction_at');

            return true;
        })
        ->andReturn([
            'files' => [$file],
            'metadata' => [
                'nextCursor' => 3,
                'total' => 9,
            ],
        ]);

    $result = app(LocalService::class)->fetch([
        'page' => 2,
        'limit' => 15,
        'source' => 'Wallhaven',
        'downloaded' => 'no',
        'file_type' => ['video'],
        'reaction_mode' => 'types',
        'reaction' => ['funny'],
        'sort' => 'reaction_at',
    ]);

    expect($result['files'])->toBe([$file])
        ->and($result['metadata']['nextCursor'])->toBe(3)
        ->and($result['metadata']['total'])->toBe(9);
});

test('fetch returns empty response without querying typesense when typed reactions are empty', function () {
    mock(LocalBrowseTypesenseGateway::class)
        ->shouldReceive('search')
        ->never();

    $result = app(LocalService::class)->fetch([
        'reaction_mode' => 'types',
        'reaction' => [],
    ]);

    expect($result)->toBe([
        'files' => [],
        'metadata' => [
            'nextCursor' => null,
            'total' => 0,
        ],
    ]);
});

test('fetch preserves explicit random seeds for typesense browse', function () {
    mock(LocalBrowseTypesenseGateway::class)
        ->shouldReceive('search')
        ->once()
        ->withArgs(function (array $context): bool {
            expect($context['sort'])->toBe('random')
                ->and($context['seed'])->toBe(12345);

            return true;
        })
        ->andReturn([
            'files' => [],
            'metadata' => [
                'nextCursor' => null,
                'total' => 0,
            ],
        ]);

    app(LocalService::class)->fetch([
        'sort' => 'random',
        'seed' => 12345,
    ]);
});

test('transform returns file models directly and includes cursor metadata', function () {
    $file = File::factory()->create();

    $fetchResult = [
        'files' => [$file],
        'metadata' => [
            'nextCursor' => 2,
            'total' => 1,
        ],
    ];

    $transformResult = $this->service->transform($fetchResult);

    expect($transformResult['files'][0])->toBeInstanceOf(File::class);
    expect($transformResult['files'][0]->id)->toBe($file->id);
    expect($transformResult['filter'])->toHaveKey('next');
    expect($transformResult['meta']['total'])->toBe(1);
});

test('default params return the expected defaults', function () {
    expect($this->service->defaultParams())->toBe([
        'limit' => 20,
        'source' => 'all',
    ]);
});
