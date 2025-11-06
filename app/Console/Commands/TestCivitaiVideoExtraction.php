<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Support\CivitaiVideoUrlExtractor;
use Illuminate\Console\Command;

class TestCivitaiVideoExtraction extends Command
{
    protected $signature = 'media:test-civitai-extraction {fileId : The file ID to test}';

    protected $description = 'Test video URL extraction from CivitAI referrer page for a specific file ID.';

    public function handle(): int
    {
        $fileId = (int) $this->argument('fileId');

        $file = File::find($fileId);
        if (! $file) {
            $this->error("File with ID {$fileId} not found.");

            return self::FAILURE;
        }

        $this->info("File ID: {$fileId}");
        $this->info("Source: {$file->source}");
        $this->info("Referrer URL: {$file->referrer_url}");
        $this->info("Thumbnail URL: {$file->thumbnail_url}");
        $this->info("MIME Type: {$file->mime_type}");
        $this->info('');

        $extractor = new CivitaiVideoUrlExtractor;

        if (! $file->referrer_url) {
            $this->error('File has no referrer URL.');

            return self::FAILURE;
        }

        $this->info('Attempting to extract video URL from referrer page...');
        $videoUrl = $extractor->extractFromReferrerUrl($file->referrer_url);

        if ($videoUrl) {
            $this->info('✓ Successfully extracted video URL:');
            $this->line($videoUrl);
        } else {
            $this->error('✗ Failed to extract video URL (returned null)');
            $this->info('');
            $this->info('This could mean:');
            $this->line("  - The referrer page doesn't contain <source> tags with mp4 files");
            $this->line("  - The referrer page couldn't be fetched");
            $this->line('  - The HTML structure is different than expected');
            $this->line('  - The page may require authentication or use JavaScript to load videos');
            $this->info('');
            $this->warn('Try manually checking the referrer URL to see the actual HTML structure.');
        }

        return $videoUrl ? self::SUCCESS : self::FAILURE;
    }
}
