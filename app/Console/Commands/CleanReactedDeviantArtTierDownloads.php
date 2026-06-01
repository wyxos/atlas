<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Services\DownloadedFileClearService;
use App\Services\FileReactionService;
use App\Services\Library\LibraryIndexSyncDispatcher;
use App\Support\SourceAccessState;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CleanReactedDeviantArtTierDownloads extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'atlas:clean-reacted-deviantart-tier-downloads
        {--id=* : Restrict cleanup to specific file IDs}
        {--chunk=500 : Number of candidate file rows to scan per chunk}
        {--dry-run : Report aggregate matches without mutating rows}
        {--force : Apply changes without an interactive confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove reactions and downloads from reacted DeviantArt tier-gated files';

    public function __construct(
        private readonly DownloadedFileClearService $downloadedFileClearService,
        private readonly FileReactionService $fileReactionService,
        private readonly LibraryIndexSyncDispatcher $libraryIndexSyncDispatcher,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $fileIds = $this->fileIds();
        if ($fileIds === null) {
            return self::FAILURE;
        }

        $chunkSize = max(1, min(5000, (int) $this->option('chunk')));
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if (! $dryRun && ! $force && ! $this->confirm('Apply this DeviantArt tier cleanup?')) {
            $this->warn('Cleanup cancelled.');

            return self::SUCCESS;
        }

        $candidateCount = $this->candidateQuery($fileIds)->count();
        $stats = $this->emptyStats($candidateCount);

        $this->line('Reacted DeviantArt tier download cleanup:');
        $this->line('- scope: '.($fileIds === [] ? 'all reacted downloaded DeviantArt candidates' : 'file IDs '.implode(', ', $fileIds)));
        $this->line('- candidate files: '.$candidateCount);
        $this->line('- chunk size: '.$chunkSize);
        $this->line('- mode: '.($dryRun ? 'dry-run' : 'apply'));

        $this->candidateQuery($fileIds)
            ->with(['reactions:id,file_id,user_id,type'])
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $files) use ($dryRun, &$stats): void {
                $this->processChunk($files, $dryRun, $stats);
            });

        if ($dryRun) {
            $this->warn('Dry run only. No rows were changed.');
        }

        $this->line('Scanned files: '.$stats['scanned']);
        $this->line('Matched tier-gated files: '.$stats['matched']);
        $this->line(($dryRun ? 'Reactions that would be removed: ' : 'Reactions removed: ').$stats['reactions_removed']);
        $this->line(($dryRun ? 'Downloads that would be cleared: ' : 'Downloads cleared: ').$stats['downloads_cleared']);
        $this->line(($dryRun ? 'Library syncs that would be queued: ' : 'Library syncs queued: ').$stats['library_syncs_queued']);
        $this->info('Reacted DeviantArt tier download cleanup complete.');

        return self::SUCCESS;
    }

    /**
     * @return array<int>|null
     */
    private function fileIds(): ?array
    {
        $ids = [];

        foreach ((array) $this->option('id') as $rawId) {
            foreach (explode(',', (string) $rawId) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }

                if (! ctype_digit($part) || (int) $part <= 0) {
                    $this->error('Every --id value must be a positive integer.');

                    return null;
                }

                $ids[] = (int) $part;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int>  $fileIds
     */
    private function candidateQuery(array $fileIds): Builder
    {
        return File::query()
            ->select([
                'id',
                'source',
                'listing_metadata',
                'downloaded',
                'downloaded_at',
                'path',
                'preview_path',
                'poster_path',
                'download_progress',
                'blacklisted_at',
            ])
            ->where('source', 'deviantart.com')
            ->where(function (Builder $query): void {
                $query
                    ->where('downloaded', true)
                    ->orWhereNotNull('path')
                    ->orWhereNotNull('preview_path')
                    ->orWhereNotNull('poster_path')
                    ->orWhere('download_progress', '>', 0);
            })
            ->whereHas('reactions')
            ->when($fileIds !== [], fn (Builder $query): Builder => $query->whereIn('id', $fileIds));
    }

    private function processChunk(Collection $files, bool $dryRun, array &$stats): void
    {
        $matchedFiles = collect();
        $matchedFileIds = [];

        foreach ($files as $file) {
            if (! $file instanceof File) {
                continue;
            }

            $stats['scanned']++;

            if (! $this->isTierGatedDeviantArtFile($file)) {
                continue;
            }

            $matchedFiles->push($file);
            $matchedFileIds[] = (int) $file->id;
            $stats['matched']++;
            $stats['reactions_removed'] += $file->reactions->count();

            if ($this->downloadedFileClearService->hasStoredAssets($file)) {
                $stats['downloads_cleared']++;
            }
        }

        if ($matchedFiles->isEmpty()) {
            return;
        }

        $stats['library_syncs_queued'] += count(array_unique($matchedFileIds));

        if ($dryRun) {
            return;
        }

        $this->fileReactionService->clearMany($matchedFiles, queueLibrarySync: false);
        $this->downloadedFileClearService->clearMany($matchedFiles, queueDelete: true, syncIndex: false);
        $this->libraryIndexSyncDispatcher->filesAndReactions($matchedFileIds);
    }

    private function isTierGatedDeviantArtFile(File $file): bool
    {
        $access = SourceAccessState::forFile($file);

        return is_array($access)
            && ($access['provider'] ?? null) === 'deviantart'
            && ($access['access_type'] ?? null) === 'tier';
    }

    private function emptyStats(int $candidateCount): array
    {
        return [
            'candidate_files' => $candidateCount,
            'scanned' => 0,
            'matched' => 0,
            'reactions_removed' => 0,
            'downloads_cleared' => 0,
            'library_syncs_queued' => 0,
        ];
    }
}
