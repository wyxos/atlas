<?php

namespace App\Support;

final class CivitAiMediaUrl
{
    public const string PRIMARY_PAGE_HOST = 'civitai.com';

    public const string NSFW_PAGE_HOST = 'civitai.red';

    public const string PRIMARY_MEDIA_HOST = 'image.civitai.com';

    public static function pageBaseUrl(bool $nsfw = false): string
    {
        return 'https://'.($nsfw ? self::NSFW_PAGE_HOST : self::PRIMARY_PAGE_HOST);
    }

    public static function isPageHost(?string $host): bool
    {
        $normalized = self::normalizeHost($host);
        if ($normalized === null) {
            return false;
        }

        return in_array($normalized, [
            self::PRIMARY_PAGE_HOST,
            'www.'.self::PRIMARY_PAGE_HOST,
            self::NSFW_PAGE_HOST,
            'www.'.self::NSFW_PAGE_HOST,
        ], true);
    }

    public static function isMediaHost(?string $host): bool
    {
        $normalized = self::normalizeHost($host);

        return $normalized === self::PRIMARY_MEDIA_HOST;
    }

    public static function isRedHost(?string $host): bool
    {
        $normalized = self::normalizeHost($host);

        return $normalized !== null && ($normalized === self::NSFW_PAGE_HOST || str_ends_with($normalized, '.'.self::NSFW_PAGE_HOST));
    }

    public static function isMediaUrl(?string $url): bool
    {
        return self::parseMediaUrl($url) !== null;
    }

    /**
     * Normalize a CivitAI image URL to the stable original=true GUID form.
     */
    public static function normalizeImageUrl(?string $url): ?string
    {
        $parts = self::parseMediaUrl($url);
        if ($parts === null) {
            return null;
        }

        if (self::isVideoExtension($parts['extension'])) {
            return null;
        }

        return "{$parts['scheme']}://".self::PRIMARY_MEDIA_HOST."/{$parts['token']}/{$parts['guid']}/original=true/{$parts['guid']}.{$parts['extension']}";
    }

    /**
     * @return array{
     *     scheme: string,
     *     host: string,
     *     token: string,
     *     guid: string,
     *     extension: string
     * }|null
     */
    private static function parseMediaUrl(?string $url): ?array
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? strtolower($parts['scheme']) : null;
        $host = isset($parts['host']) && is_string($parts['host']) ? strtolower($parts['host']) : null;
        $path = isset($parts['path']) && is_string($parts['path']) ? trim($parts['path'], '/') : null;

        if ($scheme === null || ! in_array($scheme, ['http', 'https'], true) || ! self::isMediaHost($host) || $path === null || $path === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
        if (count($segments) < 4) {
            return null;
        }

        $token = $segments[0] ?? '';
        $guid = $segments[1] ?? '';
        $filename = end($segments);
        if (! is_string($filename) || $token === '' || $guid === '') {
            return null;
        }

        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension === '') {
            return null;
        }

        return [
            'scheme' => $scheme,
            'host' => $host,
            'token' => $token,
            'guid' => $guid,
            'extension' => $extension,
        ];
    }

    private static function isVideoExtension(string $extension): bool
    {
        return in_array($extension, ['mp4', 'm4v', 'mov', 'webm'], true);
    }

    private static function normalizeHost(?string $host): ?string
    {
        if (! is_string($host)) {
            return null;
        }

        $normalized = strtolower(trim($host, ". \t\n\r\0\x0B"));

        return $normalized === '' ? null : $normalized;
    }
}
