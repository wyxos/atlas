<?php

namespace App\Support;

final class ReferrerUrlCleanup
{
    public const STRIP_ALL_QUERY_PARAMS = '*';

    public static function normalizeDomain(string $input): ?string
    {
        $trimmed = strtolower(trim($input));
        if ($trimmed === '') {
            return null;
        }

        if (str_contains($trimmed, '://')) {
            $host = parse_url($trimmed, PHP_URL_HOST);

            return is_string($host) && $host !== '' ? strtolower($host) : null;
        }

        $normalized = trim($trimmed, '.');

        return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $normalized) === 1 ? $normalized : null;
    }

    /**
     * @param  array<int, mixed>  $queryParams
     * @return array<int, string>
     */
    public static function normalizeQueryParams(array $queryParams): array
    {
        $normalized = [];

        foreach ($queryParams as $queryParam) {
            if (! is_string($queryParam)) {
                continue;
            }

            $trimmed = strtolower(trim($queryParam));
            if ($trimmed === '') {
                continue;
            }

            if ($trimmed === self::STRIP_ALL_QUERY_PARAMS) {
                return [self::STRIP_ALL_QUERY_PARAMS];
            }

            $normalized[$trimmed] = true;
        }

        return array_keys($normalized);
    }

    /**
     * @param  array<int, string>  $queryParamsToStrip
     */
    public static function cleanupForDomain(?string $url, string $domain, array $queryParamsToStrip): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }

        $normalizedDomain = self::normalizeDomain($domain);
        if ($normalizedDomain === null) {
            return $trimmed;
        }

        $parts = parse_url($trimmed);
        if (! is_array($parts)) {
            return $trimmed;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return $trimmed;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || ! self::hostMatchesDomain($host, $normalizedDomain)) {
            return $trimmed;
        }

        $normalizedQueryParamsToStrip = self::normalizeQueryParams($queryParamsToStrip);
        if ($normalizedQueryParamsToStrip === []) {
            return $trimmed;
        }

        if ($normalizedQueryParamsToStrip === [self::STRIP_ALL_QUERY_PARAMS]) {
            unset($parts['query']);

            return self::buildUrl($parts);
        }

        $query = is_string($parts['query'] ?? null) ? $parts['query'] : '';
        if ($query === '') {
            return $trimmed;
        }

        $remainingSegments = [];
        $changed = false;

        foreach (explode('&', $query) as $segment) {
            if ($segment === '') {
                continue;
            }

            $rawKey = explode('=', $segment, 2)[0];
            $normalizedKey = strtolower(urldecode($rawKey));
            if (in_array($normalizedKey, $normalizedQueryParamsToStrip, true)) {
                $changed = true;

                continue;
            }

            $remainingSegments[] = $segment;
        }

        if (! $changed) {
            return $trimmed;
        }

        if ($remainingSegments === []) {
            unset($parts['query']);
        } else {
            $parts['query'] = implode('&', $remainingSegments);
        }

        return self::buildUrl($parts);
    }

    private static function hostMatchesDomain(string $host, string $domain): bool
    {
        return $host === $domain || str_ends_with($host, '.'.$domain);
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private static function buildUrl(array $parts): string
    {
        $url = strtolower((string) $parts['scheme']).'://';

        if (isset($parts['user'])) {
            $url .= (string) $parts['user'];

            if (isset($parts['pass'])) {
                $url .= ':'.(string) $parts['pass'];
            }

            $url .= '@';
        }

        $url .= (string) ($parts['host'] ?? '');

        if (isset($parts['port'])) {
            $url .= ':'.(string) $parts['port'];
        }

        $url .= (string) ($parts['path'] ?? '');

        if (isset($parts['query']) && $parts['query'] !== '') {
            $url .= '?'.(string) $parts['query'];
        }

        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $url .= '#'.(string) $parts['fragment'];
        }

        return $url;
    }
}
