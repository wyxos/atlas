<?php

use App\Support\CivitAiMediaUrl;

it('normalizes width-based civitai image urls to the stable original form', function () {
    expect(CivitAiMediaUrl::normalizeImageUrl(
        'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/width=1032/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg'
    ))->toBe(
        'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/original=true/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg'
    );
});

it('keeps already normalized civitai image urls unchanged', function () {
    $url = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/936f0e8d-c76f-4aa7-8b5c-37349f9b7da7/original=true/936f0e8d-c76f-4aa7-8b5c-37349f9b7da7.jpeg';

    expect(CivitAiMediaUrl::normalizeImageUrl($url))->toBe($url);
});

it('returns null for non-civitai urls', function () {
    expect(CivitAiMediaUrl::normalizeImageUrl('https://example.com/image.jpg'))->toBeNull();
});

it('returns null for civitai video urls', function () {
    expect(CivitAiMediaUrl::normalizeImageUrl(
        'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/transcode=true,original=true,quality=90/13f5163c-a27e-4d39-95ec-bd2af736a08b.mp4'
    ))->toBeNull();
});
