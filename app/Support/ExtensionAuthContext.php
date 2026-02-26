<?php

namespace App\Support;

class ExtensionAuthContext
{
    public const MAX_COOKIES = 120;

    /**
     * @return array{source_url?: string, user_agent?: string, cookies?: array<int, array{domain: string, path: string, name: string, value: string, secure: bool, host_only: bool, expires: int|null}>}|null
     */
    public static function sanitize(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $sourceUrl = self::sanitizeString($raw['source_url'] ?? null, 2048);
        $sourceUrl = filter_var($sourceUrl, FILTER_VALIDATE_URL) ? $sourceUrl : null;

        $userAgent = self::sanitizeString($raw['user_agent'] ?? null, 1024);

        $cookies = [];
        $cookieDedup = [];
        $rawCookies = $raw['cookies'] ?? null;
        if (is_array($rawCookies)) {
            foreach ($rawCookies as $rawCookie) {
                $cookie = self::sanitizeCookie($rawCookie);
                if ($cookie === null) {
                    continue;
                }

                $key = strtolower($cookie['domain'])."\n{$cookie['path']}\n{$cookie['name']}";
                if (isset($cookieDedup[$key])) {
                    continue;
                }

                $cookieDedup[$key] = true;
                $cookies[] = $cookie;

                if (count($cookies) >= self::MAX_COOKIES) {
                    break;
                }
            }
        }

        $sanitized = array_filter([
            'source_url' => $sourceUrl,
            'user_agent' => $userAgent,
            'cookies' => $cookies !== [] ? $cookies : null,
        ], static fn ($value) => $value !== null && $value !== '');

        return $sanitized !== [] ? $sanitized : null;
    }

    /**
     * @return array{domain: string, path: string, name: string, value: string, secure: bool, host_only: bool, expires: int|null}|null
     */
    private static function sanitizeCookie(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $domain = self::sanitizeString($raw['domain'] ?? null, 255);
        $name = self::sanitizeString($raw['name'] ?? null, 255);
        if ($domain === null || $name === null) {
            return null;
        }

        $path = self::sanitizeString($raw['path'] ?? '/', 1024) ?? '/';
        $path = $path !== '' ? $path : '/';

        $value = self::sanitizeString($raw['value'] ?? '', 4096) ?? '';

        $expiresRaw = $raw['expires'] ?? null;
        $expires = null;
        if (is_numeric($expiresRaw)) {
            $expiresInt = (int) $expiresRaw;
            if ($expiresInt > 0) {
                $expires = $expiresInt;
            }
        }

        $hostOnlyRaw = $raw['host_only'] ?? null;
        $hostOnly = is_bool($hostOnlyRaw)
            ? $hostOnlyRaw
            : ! str_starts_with($domain, '.');

        return [
            'domain' => $domain,
            'path' => $path,
            'name' => $name,
            'value' => $value,
            'secure' => (bool) ($raw['secure'] ?? false),
            'host_only' => $hostOnly,
            'expires' => $expires,
        ];
    }

    private static function sanitizeString(mixed $value, int $maxLength): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (strlen($normalized) <= $maxLength) {
            return $normalized;
        }

        return substr($normalized, 0, $maxLength);
    }
}
