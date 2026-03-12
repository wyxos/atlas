<?php

namespace App\Support;

use App\Services\CivitAiImages;

class ContainerBrowseTabPayload
{
    /**
     * @param  array<string, mixed>  $container
     * @param  array<string, mixed>  $context
     * @return array{label: string, params: array<string, mixed>}|null
     */
    public static function build(array $container, array $context = []): ?array
    {
        $service = self::resolveService($container, $context);
        if ($service === null) {
            return null;
        }

        $params = [
            'feed' => 'online',
            'service' => $service['key'],
            'page' => 1,
            'limit' => self::resolveLimit($context),
        ];

        if (self::shouldCopyServiceFilters($context, $service['key'])) {
            $params = [
                ...$params,
                ...self::extractServiceFilters($context),
            ];
        }

        $params = match ($service['key']) {
            CivitAiImages::key() => self::applyCivitAiFilters($params, $container),
            default => null,
        };

        if ($params === null) {
            return null;
        }

        $containerLabel = self::containerLabel($container);
        if ($containerLabel === null) {
            return null;
        }

        return [
            'label' => self::formatLabel($service['label'], $containerLabel),
            'params' => $params,
        ];
    }

    /**
     * @param  array<string, mixed>  $container
     * @param  array<string, mixed>  $context
     * @return array{key: string, label: string}|null
     */
    private static function resolveService(array $container, array $context): ?array
    {
        $source = self::normalizeString($container['source'] ?? null);
        $currentFeed = self::normalizeString($context['feed'] ?? null);
        $currentService = self::normalizeString($context['service'] ?? null);

        if ($source === CivitAiImages::source()) {
            return [
                'key' => CivitAiImages::key(),
                'label' => CivitAiImages::label(),
            ];
        }

        if ($currentFeed === 'online' && $currentService === CivitAiImages::key()) {
            return [
                'key' => CivitAiImages::key(),
                'label' => CivitAiImages::label(),
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function resolveLimit(array $context): int|string
    {
        $limit = $context['limit'] ?? 20;

        if (is_int($limit) && $limit > 0) {
            return $limit;
        }

        if (is_string($limit)) {
            $trimmed = trim($limit);
            if ($trimmed !== '' && is_numeric($trimmed) && (int) $trimmed > 0) {
                return $trimmed;
            }
        }

        if (is_numeric($limit) && (int) $limit > 0) {
            return (int) $limit;
        }

        return 20;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function shouldCopyServiceFilters(array $context, string $serviceKey): bool
    {
        return self::normalizeString($context['feed'] ?? null) === 'online'
            && self::normalizeString($context['service'] ?? null) === $serviceKey;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function extractServiceFilters(array $context): array
    {
        $reserved = [
            'service' => true,
            'source' => true,
            'feed' => true,
            'tab_id' => true,
            'page' => true,
            'limit' => true,
            'next' => true,
            'serviceFilters' => true,
            'serviceFiltersByKey' => true,
        ];

        $filters = [];
        foreach ($context as $key => $value) {
            if (! is_string($key) || isset($reserved[$key])) {
                continue;
            }

            $filters[$key] = $value;
        }

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $container
     * @return array<string, mixed>|null
     */
    private static function applyCivitAiFilters(array $params, array $container): ?array
    {
        $containerType = self::normalizeString($container['type'] ?? null);
        $sourceId = self::normalizeString($container['source_id'] ?? null);

        if ($containerType === null || $sourceId === null) {
            return null;
        }

        if ($containerType === 'User') {
            $params['username'] = $sourceId;

            return $params;
        }

        if ($containerType === 'Post') {
            $params['postId'] = $sourceId;

            return $params;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $container
     */
    private static function containerLabel(array $container): ?string
    {
        $type = self::normalizeString($container['type'] ?? null);
        $value = self::normalizeString($container['source_id'] ?? null);

        if ($type === null || $value === null) {
            return null;
        }

        return "{$type} {$value}";
    }

    private static function formatLabel(string $serviceLabel, string $containerLabel): string
    {
        return "{$serviceLabel}: {$containerLabel} - 1";
    }

    private static function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
