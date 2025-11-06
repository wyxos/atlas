<?php

use App\Support\CivitaiVideoUrlExtractor;
use Illuminate\Support\Facades\Http;

it('extracts video URL from fixture HTML', function () {
    $fixturePath = base_path('tests/Fixtures/civitai-referrer-real.html');
    $html = file_get_contents($fixturePath);

    $referrerUrl = 'https://civitai.com/images/101184342';
    $expectedVideoUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/sample-uuid-1234-5678-90ab-cdef/transcode=true,original=true,quality=90/sample_video.mp4';

    Http::fake([
        $referrerUrl => Http::response($html, 200),
    ]);

    $extractor = new CivitaiVideoUrlExtractor;
    $result = $extractor->extractFromReferrerUrl($referrerUrl);

    expect($result)->toBe($expectedVideoUrl);
});
