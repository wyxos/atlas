<?php

namespace App\Http\Controllers\Concerns;

use App\Models\File;
use App\Services\Plugin\PluginServiceResolver;

trait DecoratesRemoteUrls
{
    /**
     * Decorate a remote original URL using the plugin service, when available.
     *
     * @param  array<string, mixed>  $serviceCache
     */
    protected function decorateRemoteUrl(File $file, string $url, array &$serviceCache): string
    {
        $source = (string) ($file->source ?? '');

        if ($source === '' || $url === '') {
            return $url;
        }

        if (! array_key_exists($source, $serviceCache)) {
            $serviceCache[$source] = $this->resolvePluginServiceResolver()->resolveBySource($source);
        }

        $service = $serviceCache[$source] ?? null;
        if (! $service || ! method_exists($service, 'decorateOriginalUrl')) {
            return $url;
        }

        try {
            $decorated = $service->decorateOriginalUrl($file, $url, request()->user());
            if (is_string($decorated) && $decorated !== '') {
                return $decorated;
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $url;
    }

    protected function resolvePluginServiceResolver(): PluginServiceResolver
    {
        if (property_exists($this, 'serviceResolver') && $this->serviceResolver instanceof PluginServiceResolver) {
            return $this->serviceResolver;
        }

        return app(PluginServiceResolver::class);
    }
}
