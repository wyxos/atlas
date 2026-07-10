<?php

use App\Services\Downloads\DownloadFailureMessage;
use App\Services\Downloads\YtDlpFailureMessage;

it('redacts every URI without retaining address details', function (string $message, int $expectedRedactions) {
    $sanitized = DownloadFailureMessage::normalize($message);

    expect(substr_count($sanitized, '[redacted URL]'))->toBe($expectedRedactions)
        ->and($sanitized)->not->toContain('private.example.test')
        ->and($sanitized)->not->toContain('secret-value')
        ->and($sanitized)->not->toContain('btih:private');
})->with([
    'multiple hierarchical URLs' => [
        'Request failed for https://private.example.test/secret-value and ftp://private.example.test/other.',
        2,
    ],
    'non-hierarchical schemes' => [
        'Unsupported values magnet:?xt=urn:btih:private and data:text/html,secret-value.',
        2,
    ],
]);

it('redacts a bare hostname from resolver failures', function () {
    $message = 'cURL error 6: Could not resolve host: private.example.test while requesting https://private.example.test/item';
    $sanitized = DownloadFailureMessage::normalize($message);

    expect($sanitized)->toContain('[redacted host]')
        ->and($sanitized)->toContain('[redacted URL]')
        ->and($sanitized)->not->toContain('private.example.test');
});

it('preserves filenames with numeric extensions', function () {
    expect(DownloadFailureMessage::normalize('Failed reading video.mp4'))
        ->toBe('Failed reading video.mp4');
});

it('classifies only exact unsupported URL errors', function (string $message, bool $expected) {
    expect(YtDlpFailureMessage::isUnsupportedUrl($message))->toBe($expected);
})->with([
    'plain error' => ['ERROR: Unsupported URL: https://private.example.test/item', true],
    'generic extractor error' => ['ERROR: [generic] Unsupported URL: ftp://private.example.test/item', true],
    'warning' => ['WARNING: Unsupported URL: https://private.example.test/item', false],
    'prose mention' => ['Download failed because of an unsupported URL', false],
    'near match' => ['ERROR: URL is unsupported: https://private.example.test/item', false],
    'unrelated extractor error' => ['ERROR: [generic] No media formats found', false],
]);
