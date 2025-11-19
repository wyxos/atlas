<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class HttpRateLimiter
{
    /**
     * Make an HTTP request with rate limiting and retry logic for 429 errors.
     *
     * @param  callable(): PendingRequest  $clientFactory
     * @param  array<string, mixed>  $options
     */
    public static function requestWithRetry(
        callable $clientFactory,
        string $url,
        array $options = [],
        int $maxRetries = 3,
        int $baseDelaySeconds = 1
    ): Response {
        $attempt = 0;

        while ($attempt <= $maxRetries) {
            $response = $clientFactory()->get($url, $options);

            // If successful or not a rate limit error, return immediately
            if ($response->successful() || $response->status() !== 429) {
                return $response;
            }

            // Handle 429 rate limit error
            if ($response->status() === 429) {
                $retryAfter = self::getRetryAfter($response, $baseDelaySeconds, $attempt);

                // Wait before retrying
                sleep($retryAfter);

                $attempt++;

                // If we've exhausted retries, return the 429 response
                if ($attempt > $maxRetries) {
                    return $response;
                }

                continue;
            }

            // For other errors, return immediately
            return $response;
        }

        return $response;
    }

    /**
     * Make an HTTP HEAD request with rate limiting and retry logic for 429 errors.
     *
     * @param  callable(): PendingRequest  $clientFactory
     * @param  array<string, mixed>  $options
     */
    public static function headWithRetry(
        callable $clientFactory,
        string $url,
        array $options = [],
        int $maxRetries = 3,
        int $baseDelaySeconds = 1
    ): Response {
        $attempt = 0;

        while ($attempt <= $maxRetries) {
            $response = $clientFactory()->head($url, $options);

            // If successful or not a rate limit error, return immediately
            if ($response->successful() || $response->status() !== 429) {
                return $response;
            }

            // Handle 429 rate limit error
            if ($response->status() === 429) {
                $retryAfter = self::getRetryAfter($response, $baseDelaySeconds, $attempt);

                // Wait before retrying
                sleep($retryAfter);

                $attempt++;

                // If we've exhausted retries, return the 429 response
                if ($attempt > $maxRetries) {
                    return $response;
                }

                continue;
            }

            // For other errors, return immediately
            return $response;
        }

        return $response;
    }

    /**
     * Throttle requests to a specific domain using cache-based rate limiting.
     */
    public static function throttleDomain(string $domain, int $maxRequests = 10, int $windowSeconds = 60): void
    {
        $key = "http_rate_limit:{$domain}";
        $requests = Cache::get($key, []);

        // Remove requests outside the time window
        $now = time();
        $requests = array_filter($requests, fn ($timestamp) => ($now - $timestamp) < $windowSeconds);

        // Check if we've exceeded the limit
        if (count($requests) >= $maxRequests) {
            $oldestRequest = min($requests);
            $waitTime = $windowSeconds - ($now - $oldestRequest);

            if ($waitTime > 0) {
                sleep($waitTime);

                // Re-fetch after waiting
                $requests = Cache::get($key, []);
                $requests = array_filter($requests, fn ($timestamp) => ($now - $timestamp) < $windowSeconds);
            }
        }

        // Record this request
        $requests[] = $now;
        Cache::put($key, $requests, $windowSeconds);
    }

    /**
     * Get retry delay from response headers or calculate exponential backoff.
     */
    protected static function getRetryAfter(Response $response, int $baseDelaySeconds, int $attempt): int
    {
        // Try to get Retry-After header (can be seconds or HTTP date)
        $retryAfter = $response->header('Retry-After');

        if ($retryAfter !== null) {
            // If it's numeric, it's seconds
            if (is_numeric($retryAfter)) {
                return max(1, (int) $retryAfter);
            }

            // If it's a date, calculate seconds until that time
            $retryTime = strtotime($retryAfter);
            if ($retryTime !== false) {
                $seconds = $retryTime - time();

                return max(1, $seconds);
            }
        }

        // Fallback to exponential backoff: baseDelay * 2^attempt
        return max(1, $baseDelaySeconds * (2 ** $attempt));
    }
}
