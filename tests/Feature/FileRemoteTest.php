<?php

use App\Models\File;
use App\Models\User;
use App\Services\BaseService;
use App\Services\Plugin\PluginServiceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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

it('proxies civitai original images with proper headers', function () {
    Http::fake([
        'https://image.civitai.com/*' => Http::response('image-content', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/test/image.jpg',
        'referrer_url' => 'https://civitai.com/images/123',
        'mime_type' => 'image/jpeg',
        'filename' => 'test.jpg',
    ]);

    $response = $this->actingAs($user)
        ->get(route('files.remote', ['file' => $file]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/jpeg');
    $response->assertSeeText('image-content');

    Http::assertSent(function ($request) use ($file) {
        return $request->url() === $file->url
            && $request->hasHeader('Referer', $file->referrer_url)
            && $request->hasHeader('User-Agent', 'Atlas/1.0')
            && $request->hasHeader('Accept', 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8')
            && $request->hasHeader('Origin', 'https://civitai.com');
    });
});

it('includes authorization header when civitai api key is configured', function () {
    config(['services.civitai.key' => 'test-api-key']);

    Http::fake([
        'https://image.civitai.com/*' => Http::response('image-content', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/test/image.jpg',
        'referrer_url' => 'https://civitai.com/images/123',
        'mime_type' => 'image/jpeg',
        'filename' => 'test.jpg',
    ]);

    $response = $this->actingAs($user)
        ->get(route('files.remote', ['file' => $file]));

    $response->assertOk();

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Bearer test-api-key');
    });
});

it('proxies civitai thumbnails with proper headers', function () {
    Http::fake([
        'https://image.civitai.com/*' => Http::response('thumbnail-content', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/test/image.jpg',
        'thumbnail_url' => 'https://image.civitai.com/test/thumb.jpg',
        'referrer_url' => 'https://civitai.com/images/123',
        'mime_type' => 'image/jpeg',
        'filename' => 'test.jpg',
    ]);

    $response = $this->actingAs($user)
        ->get(route('files.remote', ['file' => $file, 'thumbnail' => true]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/jpeg');
    $response->assertSeeText('thumbnail-content');

    Http::assertSent(function ($request) use ($file) {
        return $request->url() === $file->thumbnail_url
            && $request->hasHeader('Referer', $file->referrer_url)
            && $request->hasHeader('User-Agent', 'Atlas/1.0')
            && $request->hasHeader('Accept', 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8')
            && $request->hasHeader('Origin', 'https://civitai.com');
    });
});
