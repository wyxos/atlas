<?php

namespace App\Support;

use Symfony\Component\Mime\MimeTypes;

/**
 * Utility for deriving file extensions and MIME types from URLs / paths.
 */
class FileTypeDetector
{
    /**
     * Extract (lowercased) extension from a URL or path.
     */
    public static function extensionFromUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! $path) {
            return null;
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION);
        if (! $ext) {
            return null;
        }

        return strtolower($ext);
    }

    /**
     * Resolve MIME type from a URL using Symfony's MimeTypes map.
     * Falls back to application/octet-stream when unknown.
     */
    public static function mimeFromUrl(?string $url): ?string
    {
        $ext = self::extensionFromUrl($url);
        if (! $ext) {
            return null; // No extension
        }

        $mimeTypes = MimeTypes::getDefault()->getMimeTypes($ext);
        if (! empty($mimeTypes)) {
            return $mimeTypes[0];
        }

        // Fallback similar to previous implementation's default.
        return 'application/octet-stream';
    }
}
