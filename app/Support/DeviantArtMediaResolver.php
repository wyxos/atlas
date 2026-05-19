<?php

namespace App\Support;

class DeviantArtMediaResolver
{
    public static function resolve(array $row): array
    {
        $video = self::bestVideo($row);
        if ($video !== null) {
            return [
                'url' => $video['src'],
                'preview_url' => self::previewUrl($row),
                'width' => self::mediaDimension($row, 'width'),
                'height' => self::mediaDimension($row, 'height'),
                'filesize' => self::numericValue($video['filesize'] ?? null),
                'filename' => null,
                'mode' => 'video',
            ];
        }

        $content = $row['content'] ?? null;
        if (is_array($content) && isset($content['src']) && is_string($content['src']) && $content['src'] !== '') {
            return [
                'url' => $content['src'],
                'preview_url' => self::previewUrl($row),
                'width' => self::numericValue($content['width'] ?? null),
                'height' => self::numericValue($content['height'] ?? null),
                'filesize' => self::numericValue($content['filesize'] ?? null),
                'filename' => null,
                'mode' => 'content',
            ];
        }

        $preview = self::previewUrl($row);

        return [
            'url' => $preview ?? '',
            'preview_url' => $preview,
            'width' => self::mediaDimension($row, 'width'),
            'height' => self::mediaDimension($row, 'height'),
            'filesize' => null,
            'filename' => null,
            'mode' => 'preview',
        ];
    }

    public static function listingMetadata(array $row, array $media): array
    {
        $row['_atlas_media'] = [
            'mode' => $media['mode'],
            'url' => $media['url'],
            'preview_url' => $media['preview_url'],
        ];

        $username = self::artistUsername($row);
        if ($username !== null) {
            $row['user_container_source'] = 'deviantart.com';
            $row['user_container_source_id'] = $username;
            $row['user_container_referrer_url'] = self::artistGalleryUrl($username);
        }

        return $row;
    }

    public static function artistUsername(array $row): ?string
    {
        $author = $row['author'] ?? null;
        $username = is_array($author) && isset($author['username']) && is_string($author['username'])
            ? trim($author['username'])
            : null;

        if ($username !== null && self::isValidDeviantArtUsernameSegment($username)) {
            return self::normalizeUsernameSegment($username);
        }

        $sourceId = $row['user_container_source_id'] ?? null;
        if (is_string($sourceId) && self::isValidDeviantArtUsernameSegment($sourceId)) {
            return self::normalizeUsernameSegment($sourceId);
        }

        $url = $row['url'] ?? $row['referrer_url'] ?? null;
        if (! is_string($url)) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path)) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $candidate = $segments[0] ?? null;
        if (! is_string($candidate) || ! self::isValidDeviantArtUsernameSegment($candidate)) {
            return null;
        }

        return self::normalizeUsernameSegment($candidate);
    }

    public static function artistGalleryUrl(string $username): string
    {
        return 'https://www.deviantart.com/'.self::normalizeUsernameSegment($username).'/gallery';
    }

    public static function metadataPayload(array $row, array $media): array
    {
        return [
            'width' => $media['width'],
            'height' => $media['height'],
            'filesize' => $media['filesize'],
            'download_mode' => $media['mode'],
            'published_time' => $row['published_time'] ?? null,
            'is_mature' => $row['is_mature'] ?? null,
            'is_downloadable' => $row['is_downloadable'] ?? null,
            'author' => $row['author'] ?? null,
            'stats' => $row['stats'] ?? null,
        ];
    }

    private static function previewUrl(array $row): ?string
    {
        $preview = $row['preview'] ?? null;
        if (is_array($preview) && isset($preview['src']) && is_string($preview['src']) && $preview['src'] !== '') {
            return $preview['src'];
        }

        $thumb = self::largestImage($row['thumbs'] ?? null);
        if ($thumb !== null) {
            return $thumb['src'];
        }

        $content = $row['content'] ?? null;
        if (is_array($content) && isset($content['src']) && is_string($content['src']) && $content['src'] !== '') {
            return $content['src'];
        }

        return null;
    }

    private static function bestVideo(array $row): ?array
    {
        $videos = $row['videos'] ?? null;
        if (! is_array($videos)) {
            return null;
        }

        return self::largestImage($videos);
    }

    private static function largestImage(mixed $items): ?array
    {
        if (! is_array($items)) {
            return null;
        }

        $candidates = array_values(array_filter($items, fn ($item): bool => is_array($item)
            && isset($item['src'])
            && is_string($item['src'])
            && $item['src'] !== ''));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $a, array $b): int {
            $aScore = self::numericValue($a['filesize'] ?? null)
                ?? ((self::numericValue($a['width'] ?? null) ?? 0) * (self::numericValue($a['height'] ?? null) ?? 0));
            $bScore = self::numericValue($b['filesize'] ?? null)
                ?? ((self::numericValue($b['width'] ?? null) ?? 0) * (self::numericValue($b['height'] ?? null) ?? 0));

            return $bScore <=> $aScore;
        });

        return $candidates[0];
    }

    private static function mediaDimension(array $row, string $dimension): ?int
    {
        $content = $row['content'] ?? null;
        if (is_array($content)) {
            $value = self::numericValue($content[$dimension] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        $preview = $row['preview'] ?? null;
        if (is_array($preview)) {
            return self::numericValue($preview[$dimension] ?? null);
        }

        return null;
    }

    private static function numericValue(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private static function isValidDeviantArtUsernameSegment(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, [
            'about',
            'art',
            'browse',
            'daily-deviations',
            'gallery',
            'morelikethis',
            'notifications',
            'prints',
            'search',
            'settings',
            'shop',
            'watch',
        ], true)) {
            return false;
        }

        return preg_match('/^[a-z0-9_-]+$/i', $value) === 1;
    }

    private static function normalizeUsernameSegment(string $value): string
    {
        return strtolower(trim($value));
    }
}
