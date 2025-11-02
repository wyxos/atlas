<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\ComposerInstallJob;
use App\Jobs\ComposerUninstallJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PluginsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        if (! $user || ! ($user->is_admin ?? false)) {
            abort(403);
        }

        // Scan available plugins from ./plugins directory
        $availablePlugins = $this->scanAvailablePlugins();

        // Scan installed plugins from composer.lock
        $installedPlugins = $this->scanInstalledPlugins();

        // Merge both datasets
        $allKeys = array_unique(array_merge(
            array_keys($availablePlugins),
            array_keys($installedPlugins)
        ));

        $plugins = collect($allKeys)->map(function (string $packageName) use ($availablePlugins, $installedPlugins) {
            $available = $availablePlugins[$packageName] ?? null;
            $installed = $installedPlugins[$packageName] ?? null;

            return [
                'packageName' => $packageName,
                'shortName' => $available['shortName'] ?? $installed['shortName'] ?? $packageName,
                'version' => $installed['version'] ?? null,
                'description' => $available['description'] ?? $installed['description'] ?? '',
                // Only show Packagist URL if plugin is installed (meaning it was resolved by composer)
                'packagistUrl' => $installed ? 'https://packagist.org/packages/'.$packageName : null,
                'repositoryUrl' => $installed['repositoryUrl'] ?? $available['repositoryUrl'] ?? null,
                'installed' => $installed !== null,
                'available' => $available !== null,
            ];
        })->values();

        return Inertia::render('settings/Plugins', [
            'plugins' => $plugins,
        ]);
    }

    public function install(Request $request)
    {
        $user = $request->user();
        if (! $user || ! ($user->is_admin ?? false)) {
            abort(403);
        }

        $package = $request->input('package');

        // Validate package name
        if (! $this->isValidPluginPackage($package)) {
            throw ValidationException::withMessages([
                'package' => 'Invalid plugin package name',
            ]);
        }

        // Check concurrency lock
        if (Cache::has('composer_op:lock:'.$user->id)) {
            return back()->withErrors([
                'package' => 'Another composer operation is in progress. Please wait.',
            ]);
        }

        // Update composer.plugins.json
        $this->addToComposerPluginsJson($package);

        // Dispatch job
        ComposerInstallJob::dispatch($user->id, $package)->onQueue('processing');

        return back();
    }

    public function uninstall(Request $request)
    {
        $user = $request->user();
        if (! $user || ! ($user->is_admin ?? false)) {
            abort(403);
        }

        $package = $request->input('package');

        // Validate package name
        if (! $this->isValidPluginPackage($package)) {
            throw ValidationException::withMessages([
                'package' => 'Invalid plugin package name',
            ]);
        }

        // Check concurrency lock
        if (Cache::has('composer_op:lock:'.$user->id)) {
            return back()->withErrors([
                'package' => 'Another composer operation is in progress. Please wait.',
            ]);
        }

        // Get previous constraint before removal
        $previousConstraint = $this->getPackageConstraint($package);

        // Update composer.plugins.json
        $this->removeFromComposerPluginsJson($package);

        // Dispatch job
        ComposerUninstallJob::dispatch($user->id, $package, $previousConstraint)->onQueue('processing');

        return back();
    }

    protected function scanAvailablePlugins(): array
    {
        $plugins = [];
        $pattern = base_path('plugins/*/composer.json');
        $files = glob($pattern);

        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            try {
                $data = $this->readJson($file);
                $name = (string) ($data['name'] ?? '');

                if ($name === '') {
                    continue;
                }

                $parts = explode('/', $name, 2);
                $shortName = $parts[1] ?? '';

                // Filter atlas plugins, exclude contracts
                if ($shortName === '' || ! str_starts_with($shortName, 'atlas-plugin-')) {
                    continue;
                }
                if ($shortName === 'atlas-plugin-contracts') {
                    continue;
                }

                $plugins[$name] = [
                    'packageName' => $name,
                    'shortName' => $shortName,
                    'description' => (string) ($data['description'] ?? ''),
                    'repositoryUrl' => $data['support']['source'] ?? $data['homepage'] ?? null,
                ];
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $plugins;
    }

    protected function scanInstalledPlugins(): array
    {
        $plugins = [];
        $lockPath = base_path('composer.lock');

        if (! is_file($lockPath)) {
            return [];
        }

        try {
            $data = $this->readJson($lockPath);
            $packages = (array) ($data['packages'] ?? []);

            foreach ($packages as $pkg) {
                $name = (string) ($pkg['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $parts = explode('/', $name, 2);
                $shortName = $parts[1] ?? '';

                // Filter atlas plugins, exclude contracts
                if ($shortName === '' || ! str_starts_with($shortName, 'atlas-plugin-')) {
                    continue;
                }
                if ($shortName === 'atlas-plugin-contracts') {
                    continue;
                }

                $plugins[$name] = [
                    'packageName' => $name,
                    'shortName' => $shortName,
                    'version' => (string) ($pkg['version'] ?? ''),
                    'description' => (string) ($pkg['description'] ?? ''),
                    'repositoryUrl' => $pkg['source']['url'] ?? ($pkg['support']['source'] ?? null),
                ];
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $plugins;
    }

    protected function isValidPluginPackage(?string $package): bool
    {
        if (! $package || ! is_string($package)) {
            return false;
        }

        // Must match vendor/name pattern
        if (! preg_match('/^[a-z0-9_.-]+\/atlas-plugin-[a-z0-9_.-]+$/i', $package)) {
            return false;
        }

        // Deny contracts
        if (str_ends_with($package, '/atlas-plugin-contracts')) {
            return false;
        }

        return true;
    }

    protected function addToComposerPluginsJson(string $package): void
    {
        $path = base_path('composer.plugins.json');
        $data = ['require' => []];

        if (is_file($path)) {
            $data = $this->readJson($path);
        }

        $require = $data['require'] ?? [];
        // Use *@dev for local path repositories (works with any branch)
        $require[$package] = '*@dev';
        $data['require'] = $require;

        $this->writeJson($path, $data);
    }

    protected function removeFromComposerPluginsJson(string $package): void
    {
        $path = base_path('composer.plugins.json');

        if (! is_file($path)) {
            return;
        }

        $data = $this->readJson($path);
        $require = $data['require'] ?? [];

        if (isset($require[$package])) {
            unset($require[$package]);
            $data['require'] = $require;
            $this->writeJson($path, $data);
        }
    }

    protected function getPackageConstraint(string $package): ?string
    {
        $path = base_path('composer.plugins.json');

        if (! is_file($path)) {
            return null;
        }

        try {
            $data = $this->readJson($path);
            $require = $data['require'] ?? [];

            return $require[$package] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function readJson(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Failed to read file: {$path}");
        }

        $data = json_decode($contents, true);
        if (! is_array($data)) {
            throw new \RuntimeException("Invalid JSON in file: {$path}");
        }

        return $data;
    }

    protected function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $tmpPath = $path.'.tmp';

        if (file_put_contents($tmpPath, $json, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write to temporary file: {$tmpPath}");
        }

        if (! rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new \RuntimeException("Failed to rename temporary file to: {$path}");
        }
    }
}
