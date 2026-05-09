<?php

namespace App\Console\Commands;

use App\Jobs\GenerateFilePreviewAssets;
use App\Models\File;
use Illuminate\Console\Command;

class QueueMissingPreviewAssets extends Command
{
    protected $signature = 'atlas:queue-missing-previews
        {--chunk=500 : Chunk size for scanning file IDs}
        {--limit=0 : Max number of files to queue (0 = no limit)}
        {--queue=processing : Queue name to dispatch jobs to}
        {--include-stale-paths : Also queue files whose preview/poster paths use obsolete generated asset locations}
        {--dry-run : Print how many would be queued without dispatching}';

    protected $description = 'Queue preview/poster generation jobs for downloaded files missing preview assets';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $chunk = $chunk > 0 ? $chunk : 500;

        $limit = (int) $this->option('limit');
        $limit = $limit > 0 ? $limit : 0;

        $queue = (string) $this->option('queue');
        if ($queue === '') {
            $queue = 'processing';
        }

        $dryRun = (bool) $this->option('dry-run');
        $includeStalePaths = (bool) $this->option('include-stale-paths');

        $query = File::query()
            ->where('downloaded', true)
            ->whereNotNull('path')
            ->where(function ($q) use ($includeStalePaths) {
                $q
                    // Missing preview for images or videos
                    ->whereNull('preview_path')
                    // Missing poster for videos
                    ->orWhere(function ($q) {
                        $q->where(function ($qq) {
                            $qq->where('mime_type', 'like', 'video/%')
                                ->orWhere('mime_type', 'application/mp4');
                        })->whereNull('poster_path');
                    });

                if (! $includeStalePaths) {
                    return;
                }

                $q
                    ->orWhere(fn ($q) => $this->whereStaleGeneratedAssetPath($q, 'preview_path'))
                    ->orWhere(function ($q) {
                        $q->where(function ($qq) {
                            $qq->where('mime_type', 'like', 'video/%')
                                ->orWhere('mime_type', 'application/mp4');
                        })->where(fn ($qq) => $this->whereStaleGeneratedAssetPath($qq, 'poster_path'));
                    });
            })
            ->where(function ($q) {
                // Only queue jobs that can actually produce assets.
                $q->where('mime_type', 'like', 'image/%')
                    ->orWhere('mime_type', 'like', 'video/%')
                    ->orWhere('mime_type', 'application/mp4');
            })
            ->orderBy('id');

        if ($dryRun) {
            $count = $query->count();
            $this->info("Would queue {$count} file(s).");

            return self::SUCCESS;
        }

        $queued = 0;

        $query->chunkById($chunk, function ($files) use (&$queued, $limit, $queue, $includeStalePaths) {
            foreach ($files as $file) {
                if ($limit > 0 && $queued >= $limit) {
                    return false;
                }

                GenerateFilePreviewAssets::dispatch($file->id, $includeStalePaths && $this->hasStaleGeneratedAssetPath($file))->onQueue($queue);
                $queued += 1;
            }

            return true;
        });

        // Horizon/reverb workers may not print this, but it is useful in CLI.
        $this->info("Queued {$queued} file(s) to queue={$queue}.");

        return self::SUCCESS;
    }

    private function whereStaleGeneratedAssetPath($query, string $column): void
    {
        $query
            ->whereNotNull($column)
            ->where(function ($q) use ($column) {
                foreach ($this->staleGeneratedAssetPathPatterns() as $pattern) {
                    $q->orWhere($column, 'like', $pattern);
                }
            });
    }

    private function hasStaleGeneratedAssetPath(File $file): bool
    {
        return $this->isStaleGeneratedAssetPath($file->preview_path)
            || ($this->isVideoMimeType($file->mime_type) && $this->isStaleGeneratedAssetPath($file->poster_path));
    }

    private function isStaleGeneratedAssetPath(?string $path): bool
    {
        if (! is_string($path) || $path === '') {
            return false;
        }

        foreach ($this->staleGeneratedAssetPathPatterns() as $pattern) {
            $regex = '/^'.str_replace('%', '.*', preg_quote($pattern, '/')).'$/';
            if (preg_match($regex, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isVideoMimeType(?string $mimeType): bool
    {
        $mimeType = strtolower((string) $mimeType);

        return str_starts_with($mimeType, 'video/') || $mimeType === 'application/mp4';
    }

    /**
     * @return list<string>
     */
    private function staleGeneratedAssetPathPatterns(): array
    {
        return [
            'thumbnails/%',
            '%/thumbnails/%',
            'previews/%',
            '%/previews/%',
            'posters/%',
            '%/posters/%',
            '%.preview.%',
            '%.poster.%',
        ];
    }
}
