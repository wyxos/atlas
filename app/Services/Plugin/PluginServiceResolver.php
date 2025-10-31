<?php

namespace App\Services\Plugin;

use Atlas\Plugin\Contracts\BrowseService;
use Atlas\Plugin\Contracts\ServiceRegistry;
use Illuminate\Contracts\Foundation\Application;

class PluginServiceResolver
{
    public function __construct(
        protected PluginServiceLoader $loader,
        protected ServiceRegistry $registry,
        protected Application $app,
    ) {}

    public function resolve(string $key): ?BrowseService
    {
        $this->loader->load();

        $service = $this->registry->get($key);
        if (! $service) {
            return null;
        }

        return $this->app->make($service::class);
    }

    public function resolveBySource(string $source): ?BrowseService
    {
        if ($source === '') {
            return null;
        }

        $this->loader->load();

        foreach ($this->registry->all() as $key => $service) {
            if (strcasecmp($service::source(), $source) !== 0) {
                continue;
            }

            return $this->resolve(is_string($key) ? $key : $service::key()) ?? $service;
        }

        return null;
    }
}
