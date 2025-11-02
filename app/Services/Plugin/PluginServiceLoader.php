<?php

namespace App\Services\Plugin;

use Atlas\Plugin\Contracts\BrowseService;
use Atlas\Plugin\Contracts\ServiceRegistry;
use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;

class PluginServiceLoader
{
    protected bool $loaded = false;

    public function __construct(
        protected Application $app,
        protected ServiceRegistry $registry,
    ) {}

    public function load(): void
    {
        if ($this->loaded) {
            return;
        }

        if (! class_exists(InstalledVersions::class)) {
            return;
        }

        foreach (InstalledVersions::getInstalledPackages() as $package) {
            if (! str_starts_with($package, 'wyxos/atlas-plugin-')) {
                continue;
            }

            $serviceClass = $this->guessServiceClass($package);
            if ($serviceClass === null || ! class_exists($serviceClass)) {
                continue;
            }

            $service = $this->app->make($serviceClass);
            if (! $service instanceof BrowseService) {
                continue;
            }

            if ($this->registry->get($service::key()) !== null) {
                continue;
            }

            $this->registry->register($service);
        }

        $this->loaded = true;
    }

    protected function guessServiceClass(string $package): ?string
    {
        $suffix = substr($package, strlen('wyxos/atlas-plugin-'));
        if (! is_string($suffix) || $suffix === '' || $suffix === 'contracts') {
            return null;
        }

        $studly = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $suffix)));

        return "Wyxos\\Atlas\\Plugin\\{$studly}\\{$studly}Service";
    }
}
