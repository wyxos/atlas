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

        if (! $this->isVideoFile($file, $listing)) {
            return null;
        }

        $guid = $listing['guid'] ?? $this->extractGuidFromUrl($listing['url'] ?? $file->url ?? null);

        if (! $guid) {
            return null;
        }

        return $this->buildCanonicalThumbnailUrl($guid, $id);
    }

    /**
     * Ensure the listing metadata has a usable URL, resolving from the referrer when necessary.
     */
    protected function ensureListingUrl(File $file, bool $dryRun): bool
    {
        $listing = $file->listing_metadata;

        if (! is_array($listing) || empty($listing)) {
            return $this->reconstructListingFromFileUrl($file, $dryRun);
        }

        $currentUrl = $listing['url'] ?? null;

        if ($currentUrl && !filter_var($currentUrl, FILTER_VALIDATE_URL)) {
            return false;
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

        $file->save();

        return true;
    }

    /**
     * Rebuild listing information when metadata is missing but the file URL is available.
     */
    protected function reconstructListingFromFileUrl(File $file, bool $dryRun): bool
    {
        $remoteUrl = $file->url;

        if (! is_string($remoteUrl) || ! filter_var($remoteUrl, FILTER_VALIDATE_URL)) {
            return false;
        }

        $guid = $this->extractGuidFromUrl($remoteUrl);

        if (! $guid) {
            return false;
        }

        $id = $file->source_id ?? $file->id;

        if (! $id) {
            return false;
        }

        $listing = [
            'id' => $id,
            'guid' => $guid,
            'url' => $this->buildCanonicalMediaUrl($guid, $id),
        ];

        $this->ensureListingType($file, $listing);

        $file->setAttribute('listing_metadata', $listing);
        $file->setAttribute('url', $listing['url']);
        $file->setAttribute('thumbnail_url', $this->buildCanonicalThumbnailUrl($guid, $id));

        if (! $dryRun) {
            $file->save();
        }

        return true;
    }

    protected function ensureListingType(File $file, array &$listing): void
    {
        if (! isset($listing['type']) && $this->isVideoFile($file, $listing)) {
            $listing['type'] = 'Video';
        }
    }

    protected function isVideoFile(File $file, array $listing = []): bool
    {
        if (isset($listing['type']) && is_string($listing['type'])) {
            return strcasecmp($listing['type'], 'Video') === 0;
        }

        $mime = strtolower((string) ($file->mime_type ?? ''));

        return str_starts_with($mime, 'video/');
    }

    protected function buildCanonicalMediaUrl(string $guid, int $id): string
    {
        return sprintf(
            'https://image.civitai.com/%s/%s/transcode=true,original=true,quality=90/%s.mp4',
            self::CivitAiToken,
            $guid,
            $id
        );
    }

    protected function buildCanonicalThumbnailUrl(string $guid, int $id): string
    {
        return sprintf(
            'https://image.civitai.com/%s/%s/transcode=true,width=450,optimized=true/%s.mp4',
            self::CivitAiToken,
            $guid,
            $id
        );
    }

    protected function extractGuidFromUrl(?string $url): ?string
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

        if (count($segments) < 2) {
            return null;
        }

        return $segments[1] ?? null;
    }
}
