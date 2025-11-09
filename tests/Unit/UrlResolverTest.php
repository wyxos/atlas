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

    expect($resolved)->toBe('https://media.example.test/civitai/81113576/transcode=true,original=true,quality=90/falsified-video.mp4');

    Http::assertSent(fn ($request) => $request->url() === 'https://civitai.com/images/81113576');
});

it('returns null when the referrer page lacks an mp4 source', function () {
    Http::fake([
        'https://civitai.com/images/99999999' => Http::response(
            <<<'HTML'
            <html>
                <body>
                    <video>
                        <source src="https://media.example.test/civitai/99999999/preview.webm" type="video/webm">
                    </video>
                </body>
            </html>
            HTML,
            200,
            ['Content-Type' => 'text/html']
        ),
    ]);

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'mime_type' => 'video/mp4',
        'referrer_url' => 'https://civitai.com/images/99999999',
    ]);

    $resolver = new UrlResolver($file->id);

    expect($resolver->resolve())->toBeNull();
});
