<?php

use App\Support\HttpRateLimiter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

it('retries on 429 errors with exponential backoff', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push(['error' => 'Rate limited'], 429, ['Retry-After' => '1'])
            ->push(['error' => 'Rate limited'], 429, ['Retry-After' => '1'])
            ->push(['success' => true], 200),
    ]);

    $startTime = microtime(true);
    $response = HttpRateLimiter::requestWithRetry(
        fn () => Http::acceptJson(),
        'https://example.com/api',
        [],
        maxRetries: 3,
        baseDelaySeconds: 1
    );

    expect($response->status())->toBe(200);
    expect($response->json())->toHaveKey('success', true);
});

it('respects retry-after header', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push(['error' => 'Rate limited'], 429, ['Retry-After' => '2'])
            ->push(['success' => true], 200),
    ]);

    $startTime = microtime(true);
    $response = HttpRateLimiter::requestWithRetry(
        fn () => Http::acceptJson(),
        'https://example.com/api',
        [],
        maxRetries: 3,
        baseDelaySeconds: 1
    );

    expect($response->status())->toBe(200);
    // Should have waited at least 2 seconds (allowing some tolerance)
    $elapsed = microtime(true) - $startTime;
    expect($elapsed)->toBeGreaterThan(1.5);
});

it('returns 429 response after max retries', function () {
    Http::fake([
        '*' => Http::response(['error' => 'Rate limited'], 429, ['Retry-After' => '1']),
    ]);

    $response = HttpRateLimiter::requestWithRetry(
        fn () => Http::acceptJson(),
        'https://example.com/api',
        [],
        maxRetries: 2,
        baseDelaySeconds: 1
    );

    expect($response->status())->toBe(429);
});

it('throttles requests to domain', function () {
    Cache::flush();

    $domain = 'example.com';
    $maxRequests = 3;
    $windowSeconds = 60;

    // Make requests up to the limit
    for ($i = 0; $i < $maxRequests; $i++) {
        HttpRateLimiter::throttleDomain($domain, $maxRequests, $windowSeconds);
    }

    // Next request should be throttled (should wait)
    $startTime = microtime(true);
    HttpRateLimiter::throttleDomain($domain, $maxRequests, $windowSeconds);
    $elapsed = microtime(true) - $startTime;

    // Should have waited (allowing some tolerance)
    expect($elapsed)->toBeGreaterThan(0.1);
});

it('works with head requests', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push('', 429, ['Retry-After' => '1'])
            ->push('', 200),
    ]);

    $response = HttpRateLimiter::headWithRetry(
        fn () => Http::timeout(5),
        'https://example.com/api',
        [],
        maxRetries: 3,
        baseDelaySeconds: 1
    );

    expect($response->status())->toBe(200);
});

it('does not retry on non-429 errors', function () {
    Http::fake([
        '*' => Http::response(['error' => 'Not found'], 404),
    ]);

    $response = HttpRateLimiter::requestWithRetry(
        fn () => Http::acceptJson(),
        'https://example.com/api',
        [],
        maxRetries: 3,
        baseDelaySeconds: 1
    );

    expect($response->status())->toBe(404);
    Http::assertSentCount(1);
});

it('retries on connection timeout errors', function () {
    $callCount = 0;

    // Use a sequence that throws connection exceptions then succeeds
    Http::fake([
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount <= 1) {
                // Simulate connection timeout exception
                $request = new \GuzzleHttp\Psr7\Request('GET', 'https://example.com/api');
                $connectException = new \GuzzleHttp\Exception\ConnectException(
                    'cURL error 28: Operation timed out after 1000 milliseconds',
                    $request
                );
                throw new \Illuminate\Http\Client\ConnectionException($connectException);
            }

            return Http::response(['success' => true], 200);
        },
    ]);

    $response = HttpRateLimiter::requestWithRetry(
        fn () => Http::acceptJson(),
        'https://example.com/api',
        [],
        maxRetries: 2,
        baseDelaySeconds: 1
    );

    expect($response->status())->toBe(200);
    expect($response->json())->toHaveKey('success', true);
    expect($callCount)->toBe(2); // Should have retried once
});

it('retries on connection errors with exponential backoff', function () {
    $callCount = 0;
    $delays = [];
    $lastTime = microtime(true);

    Http::fake([
        '*' => function () use (&$callCount, &$delays, &$lastTime) {
            $currentTime = microtime(true);
            if ($callCount > 0) {
                $delays[] = $currentTime - $lastTime;
            }
            $lastTime = $currentTime;

            $callCount++;
            if ($callCount <= 2) {
                $request = new \GuzzleHttp\Psr7\Request('GET', 'https://example.com/api');
                $connectException = new \GuzzleHttp\Exception\ConnectException(
                    'Connection timed out',
                    $request
                );
                throw new \Illuminate\Http\Client\ConnectionException($connectException);
            }

            return Http::response(['success' => true], 200);
        },
    ]);

    $startTime = microtime(true);
    $response = HttpRateLimiter::requestWithRetry(
        fn () => Http::acceptJson(),
        'https://example.com/api',
        [],
        maxRetries: 3,
        baseDelaySeconds: 1
    );

    expect($response->status())->toBe(200);
    // Should have waited with exponential backoff (at least 1 second for first retry)
    $elapsed = microtime(true) - $startTime;
    expect($elapsed)->toBeGreaterThan(1.0);
    expect($callCount)->toBe(3); // Initial + 2 retries
});

it('throws exception after max retries on connection errors', function () {
    Http::fake([
        '*' => function () {
            $request = new \GuzzleHttp\Psr7\Request('GET', 'https://example.com/api');
            $connectException = new \GuzzleHttp\Exception\ConnectException(
                'cURL error 28: Operation timed out',
                $request
            );
            throw new \Illuminate\Http\Client\ConnectionException($connectException);
        },
    ]);

    expect(fn () => HttpRateLimiter::requestWithRetry(
        fn () => Http::acceptJson(),
        'https://example.com/api',
        [],
        maxRetries: 2,
        baseDelaySeconds: 1
    ))->toThrow(\Illuminate\Http\Client\ConnectionException::class);
});

it('does not retry on non-connection errors', function () {
    Http::fake([
        '*' => function () {
            throw new \RuntimeException('Some other error');
        },
    ]);

    expect(fn () => HttpRateLimiter::requestWithRetry(
        fn () => Http::acceptJson(),
        'https://example.com/api',
        [],
        maxRetries: 3,
        baseDelaySeconds: 1
    ))->toThrow(\RuntimeException::class);
});
