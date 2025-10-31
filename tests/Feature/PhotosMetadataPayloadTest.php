<?php

use App\Models\File;
use App\Models\FileMetadata;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Config;
use Laravel\Scout\Builder;

class FakeScoutBuilder extends Builder
{
    protected array $fakeItems = [];

    public function setFakeItems(array $items): self
    {
        $this->fakeItems = $items;

        return $this;
    }

    public function paginate($perPage = null, $pageName = 'page', $page = null): LengthAwarePaginator
    {
        $perPage = $perPage ?? max(1, count($this->fakeItems));
        $page = $page ?? Paginator::resolveCurrentPage($pageName);

        return new LengthAwarePaginator(
            $this->fakeItems,
            count($this->fakeItems),
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]
        );
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

    $container = app();
    $wasBound = $container->bound(Builder::class);
    $previous = $wasBound ? $container->getBindings()[Builder::class] : null;

    $container->bind(Builder::class, function ($app, $params) use ($file) {
        $builder = new FakeScoutBuilder(
            $params['model'],
            $params['query'],
            $params['callback'] ?? null,
            $params['softDelete'] ?? false
        );

        return $builder->setFakeItems([$file]);
    });

    try {
        $response = $this->getJson(route('photos.data', ['limit' => 10]));
        $response->assertStatus(200);
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

    $payload = $response->json('files.0');
    expect($payload)->not->toBeNull();
    expect($payload['metadata']['prompt'] ?? null)->toEqual('Scenic view of the mountains');
    expect($payload['metadata']['moderation']['reason'] ?? null)->toEqual('moderation:rule');
    expect($payload['detail_metadata']['width'] ?? null)->toEqual(1024);
    expect($payload['detail_metadata']['height'] ?? null)->toEqual(768);
});
