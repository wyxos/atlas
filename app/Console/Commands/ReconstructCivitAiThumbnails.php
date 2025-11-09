<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Services\CivitAiImages;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class ReconstructCivitAiThumbnails extends Command
{
    private const CivitAiToken = 'xG1nkqKTMzGDvpLrqFT7WA';

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'civitai:reconstruct-thumbnails
        {--dry-run : Preview the changes without saving them}
        {--chunk=500 : Number of records to process per chunk}';

    /**
     * The console command description.
     */
    protected $description = 'Rebuild CivitAI thumbnail URLs from listing metadata.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $baseQuery = File::query()
            ->where('source', CivitAiImages::SOURCE)
            ->where(function ($query) {
                $query->where('mime_type', 'like', 'video/%')
                    ->orWhere('listing_metadata->type', 'Video');
            })
            ->select(['id', 'source_id', 'url', 'thumbnail_url', 'listing_metadata', 'mime_type', 'not_found']);

        $total = (clone $baseQuery)->count();

        if ($total === 0) {
            $this->info('No CivitAI files found.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Processing %d CivitAI files%s...',
            $total,
            $dryRun ? ' (dry run)' : ''
        ));

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->start();

        $updated = 0;
        $unchanged = 0;
        $skipped = 0;
        $issues = [];

        $baseQuery->orderBy('id')->chunkById($chunkSize, function (Collection $files) use (
            $dryRun,
            $progressBar,
            &$updated,
            &$unchanged,
            &$skipped,
            &$issues
        ) {
            foreach ($files as $file) {
                if (! $this->ensureListingUrl($file, $dryRun)) {
                    $skipped++;
                    $this->warn('Could not ensure listing URL for file ID '.$file->id);
                    $progressBar->advance();
                    continue;
                }

                $thumbnail = $this->buildThumbnailUrl($file);

                if (! $thumbnail) {
                    $skipped++;
                    $this->warn('Could not build thumbnail URL for file ID '.$file->id);
                    $progressBar->advance();
                    continue;
                }

                $originalThumbnail = $file->thumbnail_url;

                if (! $dryRun) {
                    $file->thumbnail_url = $thumbnail;
                } else {
                    $file->setAttribute('thumbnail_url', $thumbnail);
                }

                $thumbnailChanged = $originalThumbnail !== $thumbnail;

                if (! $dryRun && ($thumbnailChanged || $file->isDirty(['url', 'listing_metadata']))) {
                    $file->save();
                }

                if ($thumbnailChanged) {
                    $updated++;
                } else {
                    $this->warn('Could not update listing URL for file ID '.$file->id);
                    $unchanged++;
                }

                $progressBar->advance();
            }
        });

        $progressBar->finish();
        $this->newLine(2);

        $this->info(sprintf('Updated: %d', $updated));
        $this->info(sprintf('Unchanged: %d', $unchanged));
        $this->info(sprintf('Skipped: %d', $skipped));

        if ($dryRun) {
            $this->comment('Dry run complete. No changes were written to the database.');
        }

        if (! empty($issues)) {
            $this->warn('Files that could not be processed:');
            foreach ($issues as $message) {
                $this->line(' - '.$message);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Build the expected thumbnail URL for a CivitAI file.
     */
    protected function buildThumbnailUrl(File $file): ?string
    {
        $listing = $file->listing_metadata;

        if (! is_array($listing) || empty($listing)) {
            return null;
        }

        $id = $listing['id'] ?? $file->source_id ?? $file->id;

        if (! $id) {
            return null;
        }

        $type = $listing['type'] ?? null;
        $isVideo = is_string($type) && strcasecmp($type, 'Video') === 0;

        if (! $isVideo && is_string($file->mime_type) && str_starts_with(strtolower($file->mime_type), 'video/')) {
            $isVideo = true;
        }

        if (! $isVideo) {
            return null;
        }

        [$token, $guid] = $this->extractTokenAndGuid($file);

        if (! $token || ! $guid) {
            return null;
        }

        return sprintf(
            'https://image.civitai.com/%s/%s/transcode=true,width=450,optimized=true/%s.mp4',
            $token,
            $guid,
            $id
        );
    }

    /**
     * Ensure the listing metadata has a usable URL, resolving from the referrer when necessary.
     */
    protected function ensureListingUrl(File $file, bool $dryRun): bool
    {
        $listing = $file->listing_metadata;

        if (! is_array($listing)) {
            return false;
        }

        $currentUrl = $listing['url'] ?? null;

        if ($currentUrl && filter_var($currentUrl, FILTER_VALIDATE_URL)) {
            return true;
        }

        $guid = null;
        if (isset($listing['url']) && is_string($listing['url']) && $listing['url'] !== '') {
            $guid = (string) $listing['url'];
        }

        $token = 'xG1nkqKTMzGDvpLrqFT7WA';

        if (! $guid) {
            return false;
        }

        $listing['guid'] = $guid;

        $id = $listing['id'] ?? $file->source_id ?? $file->id;

        if (! $id) {
            return false;
        }

        $base = sprintf('https://image.civitai.com/%s/%s', $token, $guid);
        $remoteUrl = sprintf('%s/transcode=true,original=true,quality=90/%s.mp4', $base, $id);

        $listing['url'] = $remoteUrl;

        $file->setAttribute('listing_metadata', $listing);
        $file->setAttribute('url', $remoteUrl);

        if ($dryRun) {
            return true;
        }

        return true;
    }
    /**
     * Extract token and guid from available metadata and URLs.
     *
     * @return array{0:?string,1:?string}
     */
    protected function extractTokenAndGuid(File $file): array
    {
        $listing = $file->listing_metadata;

        $guid = is_array($listing) && isset($listing['guid']) && is_string($listing['guid'])
            ? $listing['guid']
            : null;

        $token = is_array($listing) && isset($listing['token']) && is_string($listing['token'])
            ? $listing['token']
            : null;

        $sources = [
            is_array($listing) ? ($listing['url'] ?? null) : null,
            $file->thumbnail_url,
            $file->url,
        ];

        foreach ($sources as $source) {
            $extracted = $this->extractTokenGuidFromUrl($source);

            if (! $extracted) {
                continue;
            }

            [$parsedToken, $parsedGuid] = $extracted;

            if (! $token && $parsedToken) {
                $token = $parsedToken;
            }

            if (! $guid && $parsedGuid) {
                $guid = $parsedGuid;
            }

            if ($token && $guid) {
                break;
            }
        }

        return [$token, $guid];
    }

    /**
     * Extract token and guid from a single URL.
     *
     * @return array{0:string,1:string}|null
     */
    protected function extractTokenGuidFromUrl(?string $url): ?array
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path)));

        if (count($segments) < 3) {
            return null;
        }

        $token = $segments[0] ?? null;
        $guid = $segments[1] ?? null;

        if (! $token || ! $guid) {
            return null;
        }

        return [$token, $guid];
    }
}
