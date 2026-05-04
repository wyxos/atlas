<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Services\CivitAiMetadataRestoreService;
use Illuminate\Console\Command;

class RestoreCivitAiContainers extends Command
{
    protected $signature = 'atlas:restore-civitai-containers
        {--file-id= : Restore a single matching file ID}
        {--start-id=0 : Resume scanning after this file ID}
        {--limit=0 : Max number of matching files to request from CivitAI (0 = no limit)}
        {--chunk=500 : Number of matching file IDs to scan per database chunk}
        {--delay-ms=6500 : Delay between CivitAI API requests}
        {--dry-run : Count matching files without calling CivitAI or mutating records}';

    protected $description = 'Restore CivitAI metadata and containers for positive-reacted CivitAI files without containers';

    public function handle(CivitAiMetadataRestoreService $restoreService): int
    {
        $fileId = $this->option('file-id') !== null ? max(1, (int) $this->option('file-id')) : null;
        $startId = $fileId === null ? max(0, (int) $this->option('start-id')) : 0;
        $limit = max(0, (int) $this->option('limit'));
        $chunk = max(1, (int) $this->option('chunk'));
        $delayMs = max(0, (int) $this->option('delay-ms'));
        $dryRun = (bool) $this->option('dry-run');

        $query = $restoreService->missingContainerQuery($fileId, $startId);

        if ($dryRun) {
            $count = (clone $query)->count();
            $this->info("Dry run: {$count} matching positive-reacted CivitAI files have no containers.");

            return self::SUCCESS;
        }

        $processed = 0;
        $restored = 0;
        $skipped = 0;

        foreach ($query->lazyById($chunk, 'id') as $candidate) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }

            $file = File::query()
                ->select(['id', 'source', 'source_id', 'listing_metadata', 'detail_metadata', 'downloaded', 'blacklisted_at'])
                ->find((int) $candidate->id);

            if (! $file) {
                continue;
            }

            $result = $restoreService->restore($file);
            $processed++;

            if (($result['status'] ?? null) === 'restored') {
                $restored++;
                $this->line(sprintf(
                    'file_id=%d source_id=%s restored containers=%d->%d',
                    $result['file_id'],
                    $result['source_id'],
                    $result['containers_before'],
                    $result['containers_after'],
                ));
            } else {
                $skipped++;
                $this->line(sprintf(
                    'file_id=%d source_id=%s skipped status=%s',
                    $result['file_id'],
                    $result['source_id'] ?? '',
                    $result['status'],
                ));
            }

            if ($delayMs > 0 && ($limit === 0 || $processed < $limit)) {
                usleep($delayMs * 1000);
            }
        }

        $this->info("Done. processed={$processed} restored={$restored} skipped={$skipped}.");

        return self::SUCCESS;
    }
}
