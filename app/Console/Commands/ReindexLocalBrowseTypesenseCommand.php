<?php

namespace App\Console\Commands;

use App\Services\Local\LocalBrowseIndexSyncService;
use Illuminate\Console\Command;

class ReindexLocalBrowseTypesenseCommand extends Command
{
    protected $signature = 'atlas:reindex-local-browse {--suffix=}';

    protected $description = 'Build fresh Typesense local-browse collections and swap the live aliases.';

    public function handle(LocalBrowseIndexSyncService $syncService): int
    {
        $suffix = (string) ($this->option('suffix') ?: now()->utc()->format('Ymd_His'));

        $this->info('Rebuilding local browse Typesense collections with suffix '.$suffix);

        $summary = $syncService->rebuild($suffix, function (string $type, int $count): void {
            $this->line(sprintf('Imported %d %s docs', $count, $type));
        });

        $this->newLine();
        $this->info('Browse aliases updated.');
        $this->line('Files alias: '.$summary['files_alias']);
        $this->line('Files collection: '.$summary['files_collection']);
        $this->line('Reactions alias: '.$summary['reactions_alias']);
        $this->line('Reactions collection: '.$summary['reactions_collection']);
        $this->line('File docs source rows: '.(string) $summary['files_total']);
        $this->line('Reaction docs source rows: '.(string) $summary['reactions_total']);

        return self::SUCCESS;
    }
}
