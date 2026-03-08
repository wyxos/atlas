<?php

namespace App\Support;

class FileMimeType
{
    public static function normalize(?string $mimeType): string
    {
        return strtolower(trim((string) $mimeType));
    }

    public static function canonicalize(?string $mimeType): ?string
    {
        $normalized = self::normalize($mimeType);
        if ($normalized === '') {
            return null;
        }

        return $normalized === 'application/mp4' ? 'video/mp4' : $normalized;
    }

    public static function isImage(?string $mimeType): bool
    {
        return str_starts_with(self::normalize($mimeType), 'image/');
    }

    public static function isVideo(?string $mimeType): bool
    {
        $normalized = self::normalize($mimeType);

        return str_starts_with($normalized, 'video/') || $normalized === 'application/mp4';
    }

    public static function isAudio(?string $mimeType): bool
    {
        return str_starts_with(self::normalize($mimeType), 'audio/');
    }

    public static function category(?string $mimeType): string
    {
        if (self::isImage($mimeType)) {
            return 'image';
        }

        if (self::isVideo($mimeType)) {
            return 'video';
        }

        if (self::isAudio($mimeType)) {
            return 'audio';
        }

        return 'other';
    }
}
