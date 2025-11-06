<?php

use App\Support\CivitaiVideoUrlExtractor;
use Illuminate\Support\Facades\Http;

it('extracts video URL from fixture HTML', function () {
    $fixturePath = base_path('tests/Fixtures/civitai-referrer-101184342.html');
    $html = file_get_contents($fixturePath);

    $referrerUrl = 'https://civitai.com/images/101184342';
    $expectedVideoUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/0ff9aff5-56b1-4389-9730-a8e1fa11295f/transcode=true,original=true,quality=90/20250919_1328_video_1_apo8_ahq12.mp4';

    Http::fake([
        $referrerUrl => Http::response($html, 200),
    ]);

    $extractor = new CivitaiVideoUrlExtractor;
    $result = $extractor->extractFromReferrerUrl($referrerUrl);

    expect($result)->toBe($expectedVideoUrl);
});

