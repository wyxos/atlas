<?php

namespace App\Console\Commands;

use App\Services\Library\LibraryReindexService;
use Illuminate\Console\Command;

class ReindexLibraryTypesenseCommand extends Command
{
    protected $signature = 'atlas:reindex-library
        {--suffix= : Collection suffix to use. Defaults to a UTC timestamp}
        {--queue : Queue the reindex and return immediately}';

    protected $description = 'Build fresh Typesense library collections and swap the live aliases';

    public function handle(LibraryReindexService $reindex): int
    {
        $suffix = (string) $this->option('suffix') ?: null;

        if ($this->option('queue')) {
            [$run, $queued] = $reindex->queue($suffix);

            $queued
                ? $this->info("Queued library reindex #{$run->id} with suffix {$run->suffix}.")
                : $this->warn("Library reindex #{$run->id} is already {$run->status}; not queueing another run.");

            $this->line("Check progress with: php artisan atlas:library-reindex-status {$run->id}");

            return self::SUCCESS;
        }

        if ($activeRun = $reindex->activeRun()) {
            $this->error("Library reindex #{$activeRun->id} is already {$activeRun->status}.");

            return self::FAILURE;
        }

        $run = $reindex->createRun($suffix);
        $this->info("Rebuilding library Typesense collections with suffix {$run->suffix}");

        $summary = $reindex->run($run, function (string $type, int $count): void {
            $this->line(sprintf('Imported %d %s docs', $count, $type));
        });

        $this->newLine();
        $this->info('Library aliases updated.');
        $this->line('Files alias: '.$summary['files_alias']);
        $this->line('Files collection: '.$summary['files_collection']);
        $this->line('Reactions alias: '.$summary['reactions_alias']);
        $this->line('Reactions collection: '.$summary['reactions_collection']);
        $this->line('File docs source rows: '.(string) $summary['files_total']);
        $this->line('Reaction docs source rows: '.(string) $summary['reactions_total']);

        return self::SUCCESS;
    }
}
