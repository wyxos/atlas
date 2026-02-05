<?php

namespace App\Console\Commands;

use App\Services\ExtensionPackageService;
use Illuminate\Console\Command;

class PackageExtension extends Command
{
    protected $signature = 'atlas:extension-package {--force : Force rebuild}';

    protected $description = 'Build the Atlas browser extension zip archive';

    public function handle(ExtensionPackageService $service): int
    {
        $package = $service->package((bool) $this->option('force'));

        $this->info(sprintf(
            'Extension package ready: %s (v%s)',
            $package['path'],
            $package['version']
        ));

        return self::SUCCESS;
    }
}
