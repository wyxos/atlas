<?php

use App\Models\File;
use App\Services\UrlResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);
uses(RefreshDatabase::class);

it('resolves the original mp4 source from the referrer page', function () {
    Http::fake([
        'https://civitai.com/images/81113576' => Http::response(
            file_get_contents(base_path('tests/fixtures/civitai-image-81113576.html')),
            200,
            ['Content-Type' => 'text/html']
        ),
    ]);

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'mime_type' => 'video/mp4',
        'referrer_url' => 'https://civitai.com/images/81113576',
    ]);

    $resolver = new UrlResolver($file->id);

    $resolved = $resolver->resolve();

    expect($resolved)->toBe('https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/d98899f5-b9e8-44b8-9df2-2ee685de18cd/transcode=true,original=true,quality=90/2025-06-08T12.59.42_1.mp4');

    Http::assertSent(fn ($request) => $request->url() === 'https://civitai.com/images/81113576');
});
