<?php

namespace App\Support;

use App\Models\File;
use App\Services\CivitAiImages;
use App\Services\Plugin\PluginServiceLoader;
use Atlas\Plugin\Contracts\BrowseService;
use Atlas\Plugin\Contracts\ServiceRegistry;

class PhotoContainers
{
    public static function forFile(File $file): array
    {
        $service = self::resolveService($file);
        if (! $service) {
            return [];
        }

        $listing = self::asArray($file->listing_metadata);
        $detail = self::asArray(optional($file->metadata)->payload ?? []);

        if (empty($listing) && empty($detail)) {
            return [];
        }

        try {
            $containers = $service->containers($listing, $detail);
        } catch (\Throwable $e) {
            return [];
        }

        if (! is_array($containers)) {
            return [];
        }

        $normalized = [];
        foreach ($containers as $container) {
            if (! is_array($container)) {
                continue;
            }

            $key = (string) ($container['key'] ?? '');
            $label = (string) ($container['label'] ?? '');
            $value = $container['value'] ?? null;

            if ($key === '' || $label === '' || $value === null || $value === '') {
                continue;
            }

            $normalized[] = [
                'key' => $key,
                'label' => $label,
                'value' => $value,
            ];
        }

        return $normalized;
    }

    protected static function resolveService(File $file): ?BrowseService
    {
        $source = trim((string) ($file->source ?? ''));
        if ($source === '') {
            return null;
        }

        $lower = strtolower($source);

        $preferred = [
            strtolower(CivitAiImages::source()) => CivitAiImages::class,
            strtolower(CivitAiImages::key()) => CivitAiImages::class,
        ];

        if (isset($preferred[$lower])) {
            return app($preferred[$lower]);
        }

        try {
            app(PluginServiceLoader::class)->load();
        } catch (\Throwable $e) {
            // Ignore loader failures; fallback to whatever is already registered.
        }

        /** @var ServiceRegistry $registry */
        $registry = app(ServiceRegistry::class);

        $services = $registry->all();

        foreach ($services as $key => $service) {
            if (! $service instanceof BrowseService) {
                continue;
            }

            if (strtolower((string) $key) === $lower
                || strtolower($service::key()) === $lower
                || strtolower($service::source()) === $lower) {
                return app($service::class);
            }
        }

        return null;
    }

    protected static function asArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array) $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
