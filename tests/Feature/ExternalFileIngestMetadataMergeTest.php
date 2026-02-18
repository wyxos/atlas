<?php

use App\Models\File;
use App\Services\ExternalFileIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('preserves existing listing metadata keys when new ingests omit them', function () {
    $file = File::query()->create([
        'source' => 'youtube.com',
        'url' => 'https://www.youtube.com/embed/QTpf6orkalY',
        'original_url' => 'https://www.youtube.com/embed/QTpf6orkalY',
        'referrer_url' => 'https://www.youtube.com/embed/QTpf6orkalY',
        'filename' => 'QTpf6orkalY',
        'downloaded' => true,
        'path' => 'downloads/existing.mp4',
        'ext' => 'mp4',
        'mime_type' => 'video/mp4',
        'listing_metadata' => [
            'tag_name' => 'video',
            'page_url' => 'https://www.youtube.com/watch?v=QTpf6orkalY',
            'download_via' => 'yt-dlp',
            'download_via_reason' => 'content-type-html',
        ],
    ]);

    $service = app(ExternalFileIngestService::class);

    $service->ingest([
        'url' => $file->url,
        'original_url' => $file->url,
        'referrer_url' => 'https://www.youtube.com/watch?v=QTpf6orkalY',
        'page_title' => 'Some title',
        'tag_name' => 'video',
        // Intentionally omit download_via.
        'source' => 'youtube.com',
    ], false);

    $file->refresh();

    expect(data_get($file->listing_metadata, 'download_via'))->toBe('yt-dlp');
    expect(data_get($file->listing_metadata, 'download_via_reason'))->toBe('content-type-html');
});
