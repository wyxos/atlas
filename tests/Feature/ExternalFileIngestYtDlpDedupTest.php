<?php

use App\Services\ExternalFileIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\File;

uses(RefreshDatabase::class);

it('dedupes yt-dlp video ingests by page URL and removes client-key duplicates', function () {
    $url = 'https://www.youtube.com/shorts/2YldSt4sv_s';

    // Simulate an older buggy ingest that keyed by a per-trigger client URL fragment.
    File::query()->create([
        'source' => 'youtube.com',
        'url' => $url,
        'referrer_url' => $url.'#atlas-ext-video=old',
        'filename' => 'old',
        'downloaded' => true,
        'path' => 'downloads/existing.mp4',
        'listing_metadata' => ['tag_name' => 'video', 'download_via' => 'yt-dlp'],
    ]);

    $service = app(ExternalFileIngestService::class);

    $result = $service->ingest([
        'url' => $url,
        'original_url' => $url.'#atlas-ext-video=new',
        'referrer_url' => $url,
        'page_title' => 'Test',
        'tag_name' => 'video',
        'download_via' => 'yt-dlp',
        'reaction_type' => 'like',
        'source' => 'youtube.com',
    ], false);

    expect($result['file'])->not->toBeNull();
    expect($result['file']->referrer_url)->toBe($url);

    expect(File::query()->where('referrer_url', $url)->count())->toBe(1);
    expect(File::query()->where('referrer_url', 'like', $url.'#atlas-ext-video=%')->count())->toBe(0);
});

