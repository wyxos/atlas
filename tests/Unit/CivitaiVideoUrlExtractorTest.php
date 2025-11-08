<?php

use App\Support\CivitaiVideoUrlExtractor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('extracts civitai transcode url when uuid contains 404 digits', function () {
    $uuid = 'c864914e-3440-44da-a1d8-20386a9035b4';
    $fileName = '20250712_2238_video_1_apo8_ahq12.mp4';

    $nextData = json_encode([
        'props' => [
            'pageProps' => [
                'trpcState' => [
                    'json' => [
                        'queries' => [
                            [
                                'state' => [
                                    'data' => [
                                        'url' => $uuid,
                                        'type' => 'video',
                                        'name' => $fileName,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <script id="__NEXT_DATA__" type="application/json">{$nextData}</script>
</head>
<body></body>
</html>
HTML;

    Http::fake([
        'https://civitai.com/images/*' => Http::response($html, 200),
    ]);

    $extractor = new CivitaiVideoUrlExtractor;

    $url = $extractor->extractFromReferrerUrl('https://civitai.com/images/87908754');

    expect($url)->toBe("https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/{$uuid}/transcode=true,original=true,quality=90/{$fileName}");
});

it('returns sentinel when civitai page explicitly reports not found', function () {
    $html = <<<'HTML'
<!DOCTYPE html>
<html>
<body>
    <h1>404 Not Found</h1>
    <p>This page does not exist.</p>
</body>
</html>
HTML;

    Http::fake([
        'https://civitai.com/images/*' => Http::response($html, 200),
    ]);

    $extractor = new CivitaiVideoUrlExtractor;

    $url = $extractor->extractFromReferrerUrl('https://civitai.com/images/missing');

    expect($url)->toBe('404_NOT_FOUND');
});
