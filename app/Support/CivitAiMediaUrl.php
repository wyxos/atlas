<?php

namespace App\Support;

final class CivitAiMediaUrl
{
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

        return "{$parts['scheme']}://{$parts['host']}/{$parts['token']}/{$parts['guid']}/original=true/{$parts['guid']}.{$parts['extension']}";
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

        if ($scheme === null || ! in_array($scheme, ['http', 'https'], true) || $host !== 'image.civitai.com' || $path === null || $path === '') {
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
}
