<?php

use App\Browser;
use App\Models\File;
use App\Models\User;
use App\Services\BaseService;
use App\Services\BrowsePersister;
use App\Services\CivitAiImages;
use App\Services\Plugin\PluginServiceLoader;
use App\Services\Plugin\ServiceRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('decorates external urls when service provides a decoration hook', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    request()->replace(['source' => 'decorated-service']);

    $file = File::factory()->create([
        'source' => 'DecoratedSource',
        'url' => 'https://example.com/image.jpg',
        'listing_metadata' => ['purity' => 'nsfw'],
    ]);

    $registry = new ServiceRegistry;
    app()->instance(ServiceRegistry::class, $registry);
    app()->instance(\Atlas\Plugin\Contracts\ServiceRegistry::class, $registry);

    $this->mock(PluginServiceLoader::class, function ($mock) {
        $mock->shouldReceive('load')->atLeast()->once();
    });

    $this->mock(BrowsePersister::class, function ($mock) use ($file) {
        $mock->shouldReceive('persist')->once()->andReturn([$file]);
    });

    $this->mock(CivitAiImages::class, function ($mock) {
        $mock->allows('fetch')->andReturn(['items' => []]);
        $mock->allows('transform')->andReturn(['files' => [], 'filter' => []]);
        $mock->allows('defaultParams')->andReturn([]);
        $mock->allows('containers')->andReturn([]);
        $mock->allows('setParams')->andReturnSelf();
        $mock->allows('hotlinkProtected')->andReturn(false);
        $mock->allows('source')->andReturn('CivitAI');
        $mock->allows('label')->andReturn('CivitAI Images');
        $mock->allows('key')->andReturn('civit-ai-images');
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

        public function containers(array $listingMetadata = [], array $detailMetadata = []): array
        {
            return [];
        }

        public function decorateOriginalUrl(File $file, string $originalUrl, ?Authenticatable $viewer = null): string
        {
            return $originalUrl.'?token=secret';
        }
    };

    $registry->register($service);

    $result = Browser::handle();

    expect($result['files'])->toHaveCount(1);
    expect($result['files'][0]['original'])->toBe('https://example.com/image.jpg?token=secret');
});
