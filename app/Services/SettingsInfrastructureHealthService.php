<?php

namespace App\Services;

use App\Support\AtlasStorage;
use Illuminate\Support\Str;
use Throwable;
use Typesense\Client;

class SettingsInfrastructureHealthService
{
    public function __construct(
        private readonly Client $typesense,
        private readonly AtlasStorage $storage,
    ) {}

    /**
     * @return array{checked_at: string, typesense: array<string, mixed>, storage: array<string, mixed>}
     */
    public function check(): array
    {
        return [
            'checked_at' => now()->toISOString(),
            'typesense' => $this->checkTypesense(),
            'storage' => $this->checkStorage(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkTypesense(): array
    {
        $startedAt = microtime(true);
        $endpoint = $this->typesenseEndpoint();

        try {
            $response = $this->typesense->health->retrieve();
            $ok = ($response['ok'] ?? false) === true;

            return [
                'ok' => $ok,
                'status' => $ok ? 'healthy' : 'unhealthy',
                'endpoint' => $endpoint,
                'message' => $ok
                    ? 'Typesense health endpoint responded.'
                    : 'Typesense responded but did not report healthy.',
                'latency_ms' => $this->elapsedMilliseconds($startedAt),
                'response_ok' => $response['ok'] ?? null,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'status' => 'unhealthy',
                'endpoint' => $endpoint,
                'message' => $this->exceptionMessage($exception),
                'latency_ms' => $this->elapsedMilliseconds($startedAt),
                'response_ok' => null,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkStorage(): array
    {
        $startedAt = microtime(true);
        $root = $this->storage->rootPath();
        $appRoot = $this->storage->appRootPath();
        $probePath = '.atlas-health-ping';
        $rootExists = is_dir($root);
        $appRootExists = is_dir($appRoot);
        $readable = $appRootExists && is_readable($appRoot);
        $writable = $appRootExists && is_writable($appRoot);
        $writeOk = false;
        $readOk = false;
        $deleteOk = false;
        $message = '';

        try {
            if (! $rootExists) {
                $message = 'Atlas storage root is missing.';
            } elseif (! $appRootExists) {
                $message = 'Atlas app storage root is missing.';
            } else {
                $disk = $this->storage->disk();
                $payload = 'atlas-health:'.now()->toISOString();

                $writeOk = $disk->put($probePath, $payload);
                $readOk = $writeOk && $disk->get($probePath) === $payload;
                $deleteOk = $writeOk && $disk->delete($probePath);

                $message = $writeOk && $readOk && $deleteOk
                    ? 'Atlas storage accepted a write/read/delete probe.'
                    : 'Atlas storage probe failed.';
            }
        } catch (Throwable $exception) {
            $message = $this->exceptionMessage($exception);
        }

        $ok = $rootExists && $appRootExists && $readable && $writable && $writeOk && $readOk && $deleteOk;

        return [
            'ok' => $ok,
            'status' => $ok ? 'healthy' : 'unhealthy',
            'disk' => AtlasStorage::DISK,
            'root' => $root,
            'app_root' => $appRoot,
            'root_exists' => $rootExists,
            'app_root_exists' => $appRootExists,
            'readable' => $readable,
            'writable' => $writable,
            'write_probe' => $writeOk,
            'read_probe' => $readOk,
            'delete_probe' => $deleteOk,
            'free_bytes' => $this->diskFreeBytes($appRoot),
            'total_bytes' => $this->diskTotalBytes($appRoot),
            'namespaces' => $this->storageNamespaces(),
            'message' => $message,
            'latency_ms' => $this->elapsedMilliseconds($startedAt),
        ];
    }

    private function typesenseEndpoint(): string
    {
        /** @var array{host?: string, port?: string|int, path?: string, protocol?: string}|null $node */
        $node = config('scout.typesense.client-settings.nodes.0')
            ?? config('scout.typesense.client-settings.nearest_node');

        $host = trim((string) ($node['host'] ?? 'localhost'));
        $port = trim((string) ($node['port'] ?? '8108'));
        $path = trim((string) ($node['path'] ?? ''), '/');
        $protocol = trim((string) ($node['protocol'] ?? 'http')) ?: 'http';
        $pathSegment = $path !== '' ? "/{$path}" : '';

        return "{$protocol}://{$host}:{$port}{$pathSegment}";
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    private function exceptionMessage(Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        if ($message === '') {
            $message = class_basename($exception);
        }

        return Str::limit($message, 240);
    }

    private function diskFreeBytes(string $path): ?int
    {
        if (! is_dir($path)) {
            return null;
        }

        $bytes = disk_free_space($path);

        return is_numeric($bytes) ? (int) $bytes : null;
    }

    private function diskTotalBytes(string $path): ?int
    {
        if (! is_dir($path)) {
            return null;
        }

        $bytes = disk_total_space($path);

        return is_numeric($bytes) ? (int) $bytes : null;
    }

    /**
     * @return list<array{name: string, exists: bool}>
     */
    private function storageNamespaces(): array
    {
        $disk = $this->storage->disk();

        return array_map(
            fn (string $namespace): array => [
                'name' => $namespace,
                'exists' => $disk->exists($namespace),
            ],
            AtlasStorage::namespaces(),
        );
    }
}
