<?php

namespace App\Console\Commands;

use App\Services\FileSourceRegistry;
use Illuminate\Console\Command;

class SyncFileSources extends Command
{
    protected $signature = 'atlas:sync-file-sources';

    protected $description = 'Rebuild the file source registry used by browse controls';

    public function handle(FileSourceRegistry $registry): int
    {
        $this->info('Rebuilding file source registry from files...');

        $count = $registry->syncFromFiles();

        $this->info("Synced {$count} file source(s).");

        return self::SUCCESS;
    }
}
