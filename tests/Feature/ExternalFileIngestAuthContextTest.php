<?php

use App\Services\ExternalFileIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores sanitized extension auth_context in listing metadata', function () {
    $service = app(ExternalFileIngestService::class);

    $result = $service->ingest([
        'url' => 'https://x.com/devops_nk/status/2027073988082741620',
        'referrer_url' => 'https://x.com/devops_nk/status/2027073988082741620',
        'tag_name' => 'video',
        'download_via' => 'yt-dlp',
        'source' => 'x.com',
        'auth_context' => [
            'source_url' => 'https://x.com/devops_nk/status/2027073988082741620',
            'user_agent' => 'AtlasTestAgent/1.0',
            'cookies' => [
                [
                    'domain' => '.x.com',
                    'path' => '/',
                    'name' => 'auth_token',
                    'value' => 'abc123',
                    'secure' => true,
                    'host_only' => false,
                    'expires' => 1900000000,
                ],
            ],
        ],
    ], false);

    $file = $result['file'];

    expect($file)->not->toBeNull();
    expect(data_get($file->listing_metadata, 'auth_context.user_agent'))->toBe('AtlasTestAgent/1.0');
    expect(data_get($file->listing_metadata, 'auth_context.cookies.0.name'))->toBe('auth_token');
});
