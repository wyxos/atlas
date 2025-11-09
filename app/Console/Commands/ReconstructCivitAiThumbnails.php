<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Services\CivitAiImages;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\URL;

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
            &$skipped
        ) {
            foreach ($files as $file) {
                // check if there's listing metadata
                $metadata = $file->listing_metadata;

                // if no listing metadata, scenario 1
                if (empty($metadata) || !is_array($metadata)) {
                    // we assume $file->url is valid and attempt to retrieve the guid from it

                    $path = (string) parse_url($file->url, PHP_URL_PATH);

                    preg_match('#^/([^/]+)/([^/]+)/#', $path, $m);

                    $token = self::CivitAiToken;
                    $guid = $m[2];

                    // if we got a guid, reconstruct the thumbnail URL and url
                    // url pattern https://image.civitai.com/token/guid/transcode=true,original=true,quality=90/id.mp4
                    // thumbnail pattern https://image.civitai.com/token/guid/transcode=true,width=450,optimized=true/id.mp4

                    $url = "https://image.civitai.com/{$token}/{$guid}/transcode=true,original=true,quality=90/{$file->source_id}.mp4";
                    $thumbnail = "https://image.civitai.com/{$token}/{$guid}/transcode=true,width=450,optimized=true/{$file->source_id}.mp4";

                    // save and advance
                    if ($file->thumbnail_url !== $thumbnail || $file->url !== $url) {
                        if (! $dryRun) {
                            $file->thumbnail_url = $thumbnail;
                            $file->url = $url;
                            $file->save();
                        }
                        $updated++;
                    } else {
                        $unchanged++;
                    }

                    continue;
                }

                // if listing metadata and listing metadata url != file url, we verify if listing metadata url is valid
                $listingUrl = $metadata['url'] ?? null;

                // if url is invalid, scenario 2
                // we perform a check to see if the listing metadata url is a valid well formed url
                $validUrl = filter_var($listingUrl, FILTER_VALIDATE_URL) !== false;
                if (!$validUrl) {
                    // in this scenario, the listing metadata url is likely the guid, so we reconstruct from there
                    $token = self::CivitAiToken;
                    $guid = $metadata['url'];

                    $url = "https://image.civitai.com/{$token}/{$guid}/transcode=true,original=true,quality=90/{$file->source_id}.mp4";
                    $thumbnail = "https://image.civitai.com/{$token}/{$guid}/transcode=true,width=450,optimized=true/{$file->source_id}.mp4";

                    // save and advance
                    if ($file->thumbnail_url !== $thumbnail || $file->url !== $url) {
                        if (! $dryRun) {
                            $file->thumbnail_url = $thumbnail;
                            $file->url = $url;
                            $file->save();
                        }
                        $updated++;
                    } else {
                        $unchanged++;
                    }

                    continue;
                }

                // we extract the guid from the listing metadata url
                $path = (string) parse_url($listingUrl, PHP_URL_PATH);

                preg_match('#^/([^/]+)/([^/]+)/#', $path, $m);

                $token = self::CivitAiToken;
                $guid = $m[2];

                // reconstruct the thumbnail and url
                $url = "https://image.civitai.com/{$token}/{$guid}/transcode=true,original=true,quality=90/{$file->source_id}.mp4";
                $thumbnail = "https://image.civitai.com/{$token}/{$guid}/transcode=true,width=450,optimized=true/{$file->source_id}.mp4";
                // save and advance
                if ($file->thumbnail_url !== $thumbnail || $file->url !== $url)
                {
                    if (! $dryRun) {
                        $file->thumbnail_url = $thumbnail;
                        $file->url = $url;
                        $file->save();
                    }
                    $updated++;
                } else {
                    $unchanged++;
                }
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
}
