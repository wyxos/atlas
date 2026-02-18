<?php

use App\Models\File;
use App\Services\ExternalFileIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('dedupes yt-dlp video ingests by canonical url and strips fragments', function () {
    $url = 'https://www.youtube.com/shorts/2YldSt4sv_s';

    File::query()->create([
        'source' => 'youtube.com',
        'url' => $url,
        'referrer_url' => $url,
        'filename' => 'old',
        'downloaded' => true,
        'path' => 'downloads/existing.mp4',
        'listing_metadata' => ['tag_name' => 'video', 'download_via' => 'yt-dlp'],
    ]);

    $service = app(ExternalFileIngestService::class);

    $result = $service->ingest([
        'url' => $url.'#atlas-ext-video=new',
        'referrer_url' => $url,
        'page_title' => 'Test',
        'tag_name' => 'video',
        'download_via' => 'yt-dlp',
        'reaction_type' => 'like',
        'source' => 'youtube.com',
    ], false);

    expect($result['file'])->not->toBeNull();
    expect($result['file']->url)->toBe($url);
    expect($result['file']->referrer_url)->toBe($url);

    expect(File::query()->where('url', $url)->count())->toBe(1);
    expect(File::query()->where('url', 'like', $url.'#atlas-ext-video=%')->count())->toBe(0);
});
