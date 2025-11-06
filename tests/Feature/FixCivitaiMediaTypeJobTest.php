<?php

use App\Jobs\FixCivitaiMediaType;
use App\Models\File;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('extracts video URL from referrer page and replaces webp poster with video', function () {
    Storage::fake('atlas_app');
    Storage::fake('atlas');

    $webpBody = base64_decode('UklGRiQAAABXRUJQVlA4ICQAAAAQAAAAHAAAAABwAQCdASoIAAgAAkA4JaQAA3AA/vuUAAA=');
    Storage::disk('atlas_app')->put('downloads/sample.webp', $webpBody);

    $mp4Body = hex2bin('000000186674797069736f6d0000020069736f6d6d7034310000000866726565');
    $referrerUrl = 'https://civitai.com/images/67559727';
    $videoUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/96b33e41-8a73-4faa-8ba2-02f65e80dc35/transcode=true,original=true,quality=90/MP4_UPSCALE__00072.mp4';

    $html = "<!DOCTYPE html><html><body><video><source src=\"{$videoUrl}\" type=\"video/mp4\"></source></video></body></html>";

    Http::fake([
        $referrerUrl => Http::response($html, 200),
        $videoUrl => Http::response($mp4Body, 200, ['Content-Type' => 'video/mp4']),
    ]);

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/96b33e41-8a73-4faa-8ba2-02f65e80dc35/original=true/96b33e41-8a73-4faa-8ba2-02f65e80dc35.mp4',
        'referrer_url' => $referrerUrl,
        'thumbnail_url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/96b33e41-8a73-4faa-8ba2-02f65e80dc35/original=true/96b33e41-8a73-4faa-8ba2-02f65e80dc35.mp4',
        'filename' => 'sample.webp',
        'path' => 'downloads/sample.webp',
        'mime_type' => 'image/webp',
        'ext' => 'webp',
    ]);

    (new FixCivitaiMediaType($file->id))->handle();

    $file->refresh();

    expect($file->filename)->toBe('sample.mp4')
        ->and($file->path)->toBe('downloads/sample.mp4')
        ->and($file->mime_type)->toBe('video/mp4')
        ->and($file->ext)->toBe('mp4')
        ->and($file->url)->toBe($videoUrl)
        ->and($file->not_found)->toBeFalse()
        ->and($file->size)->toBe(strlen($mp4Body));

    Storage::disk('atlas_app')->assertMissing('downloads/sample.webp');
    Storage::disk('atlas_app')->assertExists('downloads/sample.mp4');

    Http::assertSent(function (Request $request) use ($referrerUrl, $videoUrl) {
        return $request->url() === $referrerUrl || $request->url() === $videoUrl;
    });
});

it('prefers transcoded video URL when multiple sources are available', function () {
    Storage::fake('atlas_app');
    Storage::fake('atlas');

    $webpBody = base64_decode('UklGRiQAAABXRUJQVlA4ICQAAAAQAAAAHAAAAABwAQCdASoIAAgAAkA4JaQAA3AA/vuUAAA=');
    Storage::disk('atlas_app')->put('downloads/sample.webp', $webpBody);

    $mp4Body = hex2bin('000000186674797069736f6d0000020069736f6d6d7034310000000866726565');
    $referrerUrl = 'https://civitai.com/images/67559727';
    $transcodedUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/96b33e41-8a73-4faa-8ba2-02f65e80dc35/transcode=true,original=true,quality=90/MP4_UPSCALE__00072.mp4';
    $regularUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/96b33e41-8a73-4faa-8ba2-02f65e80dc35/original=true/96b33e41-8a73-4faa-8ba2-02f65e80dc35.mp4';

    $html = "<!DOCTYPE html><html><body><video>
        <source src=\"{$regularUrl}\" type=\"video/mp4\"></source>
        <source src=\"{$transcodedUrl}\" type=\"video/mp4\"></source>
    </video></body></html>";

    Http::fake([
        $referrerUrl => Http::response($html, 200),
        $transcodedUrl => Http::response($mp4Body, 200, ['Content-Type' => 'video/mp4']),
    ]);

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'url' => $regularUrl,
        'referrer_url' => $referrerUrl,
        'thumbnail_url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/96b33e41-8a73-4faa-8ba2-02f65e80dc35/original=true/96b33e41-8a73-4faa-8ba2-02f65e80dc35.mp4',
        'filename' => 'sample.webp',
        'path' => 'downloads/sample.webp',
        'mime_type' => 'image/webp',
        'ext' => 'webp',
    ]);

    (new FixCivitaiMediaType($file->id))->handle();

    $file->refresh();

    expect($file->url)->toBe($transcodedUrl)
        ->and($file->mime_type)->toBe('video/mp4');
});

it('skips files that do not match criteria', function () {
    Storage::fake('atlas_app');
    Storage::fake('atlas');

    $webpBody = base64_decode('UklGRiQAAABXRUJQVlA4ICQAAAAQAAAAHAAAAABwAQCdASoIAAgAAkA4JaQAA3AA/vuUAAA=');
    Storage::disk('atlas_app')->put('downloads/sample.webp', $webpBody);

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/foo.jpg',
        'referrer_url' => 'https://civitai.com/images/123',
        'thumbnail_url' => 'https://image.civitai.com/thumb.jpg',
        'filename' => 'sample.webp',
        'path' => 'downloads/sample.webp',
        'mime_type' => 'image/webp',
        'ext' => 'webp',
    ]);

    Http::fake();

    (new FixCivitaiMediaType($file->id))->handle();

    $file->refresh();

    // File should remain unchanged
    expect($file->filename)->toBe('sample.webp')
        ->and($file->path)->toBe('downloads/sample.webp')
        ->and($file->mime_type)->toBe('image/webp');

    // No HTTP requests should be made
    Http::assertNothingSent();
});

it('skips files without referrer URL', function () {
    Storage::fake('atlas_app');
    Storage::fake('atlas');

    $webpBody = base64_decode('UklGRiQAAABXRUJQVlA4ICQAAAAQAAAAHAAAAABwAQCdASoIAAgAAkA4JaQAA3AA/vuUAAA=');
    Storage::disk('atlas_app')->put('downloads/sample.webp', $webpBody);

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/foo.mp4',
        'referrer_url' => null,
        'thumbnail_url' => 'https://image.civitai.com/thumb.mp4',
        'filename' => 'sample.webp',
        'path' => 'downloads/sample.webp',
        'mime_type' => 'image/webp',
        'ext' => 'webp',
    ]);

    Http::fake();

    (new FixCivitaiMediaType($file->id))->handle();

    $file->refresh();

    // File should remain unchanged
    expect($file->filename)->toBe('sample.webp')
        ->and($file->mime_type)->toBe('image/webp');

    Http::assertNothingSent();
});

it('skips files when video URL cannot be extracted', function () {
    Storage::fake('atlas_app');
    Storage::fake('atlas');

    $webpBody = base64_decode('UklGRiQAAABXRUJQVlA4ICQAAAAQAAAAHAAAAABwAQCdASoIAAgAAkA4JaQAA3AA/vuUAAA=');
    Storage::disk('atlas_app')->put('downloads/sample.webp', $webpBody);

    $referrerUrl = 'https://civitai.com/images/67559727';
    $html = '<!DOCTYPE html><html><body><p>No video here</p></body></html>';

    Http::fake([
        $referrerUrl => Http::response($html, 200),
    ]);

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/foo.mp4',
        'referrer_url' => $referrerUrl,
        'thumbnail_url' => 'https://image.civitai.com/thumb.mp4',
        'filename' => 'sample.webp',
        'path' => 'downloads/sample.webp',
        'mime_type' => 'image/webp',
        'ext' => 'webp',
    ]);

    (new FixCivitaiMediaType($file->id))->handle();

    $file->refresh();

    // File should remain unchanged
    expect($file->filename)->toBe('sample.webp')
        ->and($file->mime_type)->toBe('image/webp');
});

it('extracts video URL from real fixture for file ID 1253355', function () {
    Storage::fake('atlas_app');
    Storage::fake('atlas');

    $webpBody = base64_decode('UklGRiQAAABXRUJQVlA4ICQAAAAQAAAAHAAAAABwAQCdASoIAAgAAkA4JaQAA3AA/vuUAAA=');
    Storage::disk('atlas_app')->put('downloads/sample.webp', $webpBody);

    $mp4Body = hex2bin('000000186674797069736f6d0000020069736f6d6d7034310000000866726565');
    $referrerUrl = 'https://civitai.com/images/67559727';
    $expectedVideoUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/sample-uuid-1234-5678-90ab-cdef/transcode=true,original=true,quality=90/sample_video.mp4';

    // Load real fixture HTML (sanitized)
    $html = file_get_contents(base_path('tests/Fixtures/civitai-referrer-real.html'));

    Http::fake([
        $referrerUrl => Http::response($html, 200),
        $expectedVideoUrl => Http::response($mp4Body, 200, ['Content-Type' => 'video/mp4']),
    ]);

    $file = File::factory()->create([
        'id' => 1253355,
        'source' => 'CivitAI',
        'source_id' => 67559727,
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/sample-uuid-1234-5678-90ab-cdef/original=true/sample_video.mp4',
        'referrer_url' => $referrerUrl,
        'thumbnail_url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/sample-uuid-1234-5678-90ab-cdef/original=true/sample_video.mp4',
        'filename' => 'sample.webp',
        'path' => 'downloads/sample.webp',
        'mime_type' => 'image/webp',
        'ext' => 'webp',
    ]);

    (new FixCivitaiMediaType($file->id))->handle();

    $file->refresh();

    expect($file->filename)->toBe('sample.mp4')
        ->and($file->path)->toBe('downloads/sample.mp4')
        ->and($file->mime_type)->toBe('video/mp4')
        ->and($file->ext)->toBe('mp4')
        ->and($file->url)->toBe($expectedVideoUrl)
        ->and($file->not_found)->toBeFalse();

    Storage::disk('atlas_app')->assertMissing('downloads/sample.webp');
    Storage::disk('atlas_app')->assertExists('downloads/sample.mp4');
});

it('extracts video URL from real fixture for file ID 1251390', function () {
    Storage::fake('atlas_app');
    Storage::fake('atlas');

    $webpBody = base64_decode('UklGRiQAAABXRUJQVlA4ICQAAAAQAAAAHAAAAABwAQCdASoIAAgAAkA4JaQAA3AA/vuUAAA=');
    Storage::disk('atlas_app')->put('downloads/sample.webp', $webpBody);

    $mp4Body = hex2bin('000000186674797069736f6d0000020069736f6d6d7034310000000866726565');
    $referrerUrl = 'https://civitai.com/images/101184342';
    $expectedVideoUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/sample-uuid-1234-5678-90ab-cdef/transcode=true,original=true,quality=90/sample_video.mp4';

    // Load real fixture HTML (sanitized)
    $html = file_get_contents(base_path('tests/Fixtures/civitai-referrer-real.html'));

    Http::fake([
        $referrerUrl => Http::response($html, 200),
        $expectedVideoUrl => Http::response($mp4Body, 200, ['Content-Type' => 'video/mp4']),
    ]);

    $file = File::factory()->create([
        'id' => 1251390,
        'source' => 'CivitAI',
        'source_id' => 101184342,
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/sample-uuid-1234-5678-90ab-cdef/original=true/sample_video.mp4',
        'referrer_url' => $referrerUrl,
        'thumbnail_url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/sample-uuid-1234-5678-90ab-cdef/original=true/sample_video.mp4',
        'filename' => 'sample.webp',
        'path' => 'downloads/sample.webp',
        'mime_type' => 'image/webp',
        'ext' => 'webp',
    ]);

    (new FixCivitaiMediaType($file->id))->handle();

    $file->refresh();

    expect($file->filename)->toBe('sample.mp4')
        ->and($file->path)->toBe('downloads/sample.mp4')
        ->and($file->mime_type)->toBe('video/mp4')
        ->and($file->ext)->toBe('mp4')
        ->and($file->url)->toBe($expectedVideoUrl)
        ->and($file->not_found)->toBeFalse();

    Storage::disk('atlas_app')->assertMissing('downloads/sample.webp');
    Storage::disk('atlas_app')->assertExists('downloads/sample.mp4');
});
