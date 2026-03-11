<?php

use App\Support\ReferrerUrlCleanup;

test('referrer url cleanup strips selected query params for matching domains', function () {
    expect(ReferrerUrlCleanup::cleanupForDomain(
        'https://domain.com/?id=123&tag=blue+sky',
        'domain.com',
        ['tag', 'tags'],
    ))->toBe('https://domain.com/?id=123');
});

test('referrer url cleanup strips all query params when wildcard is configured', function () {
    expect(ReferrerUrlCleanup::cleanupForDomain(
        'https://domain.com/post/view?id=123&tag=blue#image-2',
        'domain.com',
        ['*'],
    ))->toBe('https://domain.com/post/view#image-2');
});

test('referrer url cleanup normalizes wildcard query params to a single entry', function () {
    expect(ReferrerUrlCleanup::normalizeQueryParams(['tag', '*', 'tags']))
        ->toBe(['*']);
});
