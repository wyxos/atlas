<?php

use App\Support\ExtensionAuthContext;

it('sanitizes extension auth context and deduplicates cookies', function () {
    $sanitized = ExtensionAuthContext::sanitize([
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
            [
                'domain' => '.x.com',
                'path' => '/',
                'name' => 'auth_token',
                'value' => 'duplicate',
                'secure' => true,
                'host_only' => false,
                'expires' => 1900000000,
            ],
            [
                'domain' => 'x.com',
                'path' => '/',
                'name' => 'ct0',
                'value' => 'csrf',
                'secure' => true,
                'expires' => null,
            ],
        ],
    ]);

    expect($sanitized)->not->toBeNull();
    expect($sanitized['source_url'])->toBe('https://x.com/devops_nk/status/2027073988082741620');
    expect($sanitized['user_agent'])->toBe('AtlasTestAgent/1.0');
    expect($sanitized['cookies'])->toHaveCount(2);
    expect($sanitized['cookies'][0]['name'])->toBe('auth_token');
    expect($sanitized['cookies'][1]['host_only'])->toBeTrue();
    expect($sanitized['cookies'][1]['expires'])->toBeNull();
});

it('returns null for empty or invalid auth context payloads', function () {
    expect(ExtensionAuthContext::sanitize(null))->toBeNull();
    expect(ExtensionAuthContext::sanitize(['cookies' => [['value' => 'x']]]))->toBeNull();
});
