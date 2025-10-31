<?php

use App\Models\File;
use App\Models\User;
use App\Services\BaseService;
use App\Services\Plugin\PluginServiceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

uses(RefreshDatabase::class);

it('delegates remote proxying to the matching browse service', function () {
    $service = new class extends BaseService
    {
        public const KEY = 'test-proxy';

        public const SOURCE = 'TestProxy';

        public const HOTLINK_PROTECTED = true;

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

        public function shouldProxyOriginal(File $file): bool
        {
            return true;
        }

        public function proxyOriginal(Request $request, File $file): SymfonyResponse
        {
            return response('proxied-body', 200, [
                'Content-Type' => 'image/jpeg',
                'X-Test-Proxy' => 'true',
            ]);
        }
    };

    $this->mock(PluginServiceResolver::class, function ($mock) use ($service) {
        $mock->shouldReceive('resolveBySource')
            ->once()
            ->with('TestProxy')
            ->andReturn($service);
    });

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'TestProxy',
        'url' => 'https://example.com/image.jpg',
        'referrer_url' => 'https://example.com/post/1',
    ]);

    $response = $this->actingAs($user)
        ->get(route('files.remote', ['file' => $file]));

    $response->assertOk();
    $response->assertHeader('X-Test-Proxy', 'true');
    $response->assertSeeText('proxied-body');
});
