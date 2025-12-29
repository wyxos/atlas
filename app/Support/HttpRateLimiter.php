<?php

namespace App\Support;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Client\ConnectionException as LaravelConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HttpRateLimiter
{
    /**
     * Make an HTTP request with rate limiting and retry logic for 429 errors and connection timeouts.
     */
    public static function requestWithRetry(
        callable $clientFactory,
        string $url,
        array $options = [],
        int $maxRetries = 3,
        int $baseDelaySeconds = 1
    ): Response {
        $attempt = 0;
        $lastException = null;

        while ($attempt <= $maxRetries) {
            try {
                $response = $clientFactory()->get($url, $options);

                // If successful, return immediately
                if ($response->successful()) {
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

                // For other HTTP errors, return immediately
                return $response;
            } catch (LaravelConnectionException $e) {
                $lastException = $e;
                $shouldRetry = self::shouldRetryConnectionError($e, $attempt, $maxRetries);

                if (! $shouldRetry) {
                    // Re-throw if we shouldn't retry
                    throw $e;
                }

                $retryAfter = self::calculateRetryDelay($baseDelaySeconds, $attempt);
                Log::warning('Connection error during HTTP request, retrying', [
                    'url' => $url,
                    'attempt' => $attempt + 1,
                    'max_retries' => $maxRetries,
                    'retry_after' => $retryAfter,
                    'error' => $e->getMessage(),
                ]);

                sleep($retryAfter);
                $attempt++;
            } catch (\Throwable $e) {
                // Check if it's a Guzzle connection exception wrapped in the underlying exception
                $underlyingException = $e->getPrevious();
                if ($underlyingException instanceof ConnectException || $underlyingException instanceof RequestException) {
                    $lastException = $e;
                    $shouldRetry = self::shouldRetryConnectionError($e, $attempt, $maxRetries);

                    if (! $shouldRetry) {
                        throw $e;
                    }

                    $retryAfter = self::calculateRetryDelay($baseDelaySeconds, $attempt);
                    Log::warning('Connection error during HTTP request, retrying', [
                        'url' => $url,
                        'attempt' => $attempt + 1,
                        'max_retries' => $maxRetries,
                        'retry_after' => $retryAfter,
                        'error' => $e->getMessage(),
                    ]);

                    sleep($retryAfter);
                    $attempt++;
                } else {
                    // Not a connection error, re-throw immediately
                    throw $e;
                }
            }
        }

        // If we exhausted retries and have a last exception, throw it
        if ($lastException !== null) {
            throw $lastException;
        }

        // Fallback: return the last response if available (shouldn't happen)
        return $clientFactory()->get($url, $options);
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
        return self::calculateRetryDelay($baseDelaySeconds, $attempt);
    }

    /**
     * Calculate retry delay using exponential backoff.
     */
    protected static function calculateRetryDelay(int $baseDelaySeconds, int $attempt): int
    {
        // Exponential backoff: baseDelay * 2^attempt
        // Cap at 60 seconds to avoid excessive delays
        return min(60, max(1, $baseDelaySeconds * (2 ** $attempt)));
    }

    /**
     * Determine if a connection error should be retried.
     */
    protected static function shouldRetryConnectionError(\Throwable $e, int $attempt, int $maxRetries): bool
    {
        if ($attempt >= $maxRetries) {
            return false;
        }

        $message = strtolower($e->getMessage());

        // Retry on timeout errors (cURL error 28)
        if (str_contains($message, 'timeout') || str_contains($message, 'timed out') || str_contains($message, 'curl error 28')) {
            return true;
        }

        // Retry on connection errors
        if (str_contains($message, 'connection') || str_contains($message, 'connect')) {
            return true;
        }

        // Check underlying exception for Guzzle errors
        $underlying = $e->getPrevious();
        if ($underlying instanceof ConnectException) {
            return true;
        }

        if ($underlying instanceof RequestException) {
            $underlyingMessage = strtolower($underlying->getMessage());
            if (str_contains($underlyingMessage, 'timeout') || str_contains($underlyingMessage, 'curl error 28')) {
                return true;
            }
        }

        return false;
    }
}
