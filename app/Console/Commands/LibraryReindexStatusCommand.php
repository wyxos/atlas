<?php

namespace App\Console\Commands;

use App\Models\LibraryReindexRun;
use App\Services\Library\LibraryReindexService;
use Illuminate\Console\Command;

class LibraryReindexStatusCommand extends Command
{
    protected $signature = 'atlas:library-reindex-status
        {run? : Reindex run ID. Defaults to latest run}
        {--json : Output machine-readable JSON}';

    protected $description = 'Show progress for a queued library Typesense reindex run';

    public function handle(LibraryReindexService $reindex): int
    {
        $run = $this->argument('run')
            ? LibraryReindexRun::query()->find((int) $this->argument('run'))
            : LibraryReindexRun::query()->latest()->first();

        if (! $run) {
            $this->warn('No library reindex run found.');

            return self::SUCCESS;
        }

        $summary = $reindex->summary($run);

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info("Library reindex #{$run->id}: {$run->status} ({$run->phase})");
        $this->line("Suffix: {$run->suffix}");
        $this->line(sprintf(
            'Files: %s / %s',
            number_format($run->files_indexed),
            number_format($run->files_total),
        ));
        $this->line(sprintf(
            'Reactions: %s / %s',
            number_format($run->reactions_indexed),
            number_format($run->reactions_total),
        ));

        if ($run->error) {
            $this->error($run->error);
        }

        return self::SUCCESS;
    }
}
