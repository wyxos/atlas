<?php

namespace App\Support;

final class DeviantArtPageUrl
{
    public static function normalize(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }

        $parts = parse_url($trimmed);
        if (! is_array($parts)) {
            return null;
        }

        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? strtolower($parts['scheme']) : null;
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = isset($parts['host']) && is_string($parts['host']) ? strtolower($parts['host']) : null;
        if (! in_array($host, ['deviantart.com', 'www.deviantart.com'], true)) {
            return null;
        }

        $path = isset($parts['path']) && is_string($parts['path']) ? '/'.trim($parts['path'], '/') : '';
        if ($path === '' || $path === '/') {
            return null;
        }

        return 'https://www.deviantart.com'.$path;
    }
}
