<?php

namespace App\Support;

final class CivitAiPageUrls
{
    public static function imageReferrer(string $id, array $metadata): string
    {
        return self::baseUrlForMetadata($metadata)."/images/{$id}";
    }

    public static function containerReferrer(
        string $type,
        string $sourceId,
        array $metadata,
        array $resourceContainer = [],
    ): ?string {
        $baseUrl = self::baseUrlForMetadata($metadata);

        return match ($type) {
            'Post' => "{$baseUrl}/posts/{$sourceId}",
            'User' => "{$baseUrl}/user/{$sourceId}",
            'Checkpoint', 'LoRA' => self::resourceContainerReferrer($sourceId, $metadata, $resourceContainer),
            default => null,
        };
    }

    public static function baseUrlForMetadata(array $metadata): string
    {
        return CivitAiMediaUrl::pageBaseUrl(self::shouldUseRedReferrer($metadata));
    }

    public static function shouldUseRedReferrer(array $metadata): bool
    {
        if (self::isNsfwMetadata($metadata)) {
            return true;
        }

        foreach ([
            'file_referrer_url',
            'page_url',
            'post_container_referrer_url',
            'user_container_referrer_url',
        ] as $key) {
            if (self::hasRedCivitAiHost($metadata[$key] ?? null)) {
                return true;
            }
        }

        $resourceContainers = $metadata['resource_containers'] ?? null;
        if (is_array($resourceContainers)) {
            foreach ($resourceContainers as $resourceContainer) {
                if (is_array($resourceContainer) && self::hasRedCivitAiHost($resourceContainer['referrerUrl'] ?? null)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function resourceContainerReferrer(
        string $sourceId,
        array $metadata,
        array $resourceContainer,
    ): ?string {
        $submittedReferrer = isset($resourceContainer['referrerUrl']) && is_string($resourceContainer['referrerUrl'])
            ? trim($resourceContainer['referrerUrl'])
            : null;
        if ($submittedReferrer !== null && $submittedReferrer !== '') {
            return self::normalizeSubmittedCivitAiReferrer($submittedReferrer, $metadata);
        }

        $modelId = isset($resourceContainer['modelId']) ? (int) $resourceContainer['modelId'] : null;
        if ($modelId !== null && $modelId > 0) {
            return self::baseUrlForMetadata($metadata)."/models/{$modelId}?modelVersionId={$sourceId}";
        }

        return null;
    }

    private static function isNsfwMetadata(array $metadata): bool
    {
        $nsfw = self::resolveBoolean($metadata['nsfw'] ?? null);
        if ($nsfw !== null) {
            return $nsfw;
        }

        $level = $metadata['nsfwLevel'] ?? null;
        if (is_int($level) || is_float($level)) {
            return $level > 0;
        }

        if (! is_string($level)) {
            return false;
        }

        $normalized = strtolower(trim($level));

        return $normalized !== '' && ! in_array($normalized, ['0', 'none', 'sfw'], true);
    }

    private static function resolveBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['true', '1', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['false', '0', 'no', 'off'], true)) {
            return false;
        }

        return null;
    }

    private static function hasRedCivitAiHost(mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        $host = parse_url($value, PHP_URL_HOST);

        return is_string($host) && CivitAiMediaUrl::isRedHost($host);
    }

    private static function normalizeSubmittedCivitAiReferrer(string $referrer, array $metadata): string
    {
        if (! self::shouldUseRedReferrer($metadata)) {
            return $referrer;
        }

        $parts = parse_url($referrer);
        if (! is_array($parts)) {
            return $referrer;
        }

        $host = isset($parts['host']) && is_string($parts['host']) ? strtolower($parts['host']) : null;
        if ($host === null || ! in_array($host, [CivitAiMediaUrl::PRIMARY_PAGE_HOST, 'www.'.CivitAiMediaUrl::PRIMARY_PAGE_HOST], true)) {
            return $referrer;
        }

        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
        $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '';
        $query = isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) && is_string($parts['fragment']) && $parts['fragment'] !== '' ? '#'.$parts['fragment'] : '';

        return "{$scheme}://".CivitAiMediaUrl::NSFW_PAGE_HOST.$path.$query.$fragment;
    }
}
