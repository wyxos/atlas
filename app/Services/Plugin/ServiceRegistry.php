<?php

namespace App\Services\Plugin;

use Atlas\Plugin\Contracts\BrowseService;
use Atlas\Plugin\Contracts\ServiceRegistry as ServiceRegistryContract;

class ServiceRegistry implements ServiceRegistryContract
{
    /**
     * @var array<string, BrowseService>
     */
    protected array $services = [];

    public function register(BrowseService $service): void
    {
        $this->services[$service::key()] = $service;
    }

    public function all(): array
    {
        return $this->services;
    }

    public function get(string $key): ?BrowseService
    {
        return $this->services[$key] ?? null;
    }
}
