<?php

namespace App\Console\Commands;

use App\Models\File;
use Illuminate\Console\Command;

class AutoBlacklist extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'auto:blacklist {--dry-run : Show matches without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan CivitAI files for blacklisted content in prompts';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $searchString = 'fat'; // Hardcoded string to search for

        $this->info('Auto Blacklist Scanner');
        $this->info('===================');
        $this->info("Searching for: '{$searchString}'");

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $this->newLine();

        // Build the base query for CivitAI files with metadata
        $baseQuery = File::where('source', 'CivitAI')
            ->whereHas('metadata', function ($query) {
                $query->whereNotNull('payload->data');
            })
            ->where('downloaded', false)
            ->where('is_blacklisted', false);

        // Get total count of files to process
        $totalFiles = $baseQuery->count();

        if ($totalFiles === 0) {
            $this->info('No CivitAI files with metadata found.');
            return Command::SUCCESS;
        }

        $this->info("Found {$totalFiles} CivitAI files with metadata to scan.");

        $bar = $this->output->createProgressBar($totalFiles);
        $bar->start();

        $matchedFiles = [];
        $processedCount = 0;
        $filesWithPrompts = 0;

        // Process files in chunks to avoid memory issues
        $baseQuery->with('metadata')
            ->chunkById(100, function ($files) use (&$matchedFiles, &$processedCount, &$filesWithPrompts, $searchString, $bar) {
                foreach ($files as $file) {
                    $processedCount++;

                    // Check if file has metadata and data
                    if ($file->metadata && isset($file->metadata->payload['data'])) {
                        $data = $file->metadata->payload['data'];

                        // Check if prompt exists in the data
                        $prompt = null;
                        if (isset($data['meta']['prompt'])) {
                            $prompt = $data['meta']['prompt'];
                        } elseif (isset($data['prompt'])) {
                            $prompt = $data['prompt'];
                        }

                        if ($prompt) {
                            $filesWithPrompts++;

                            // Check if prompt contains the search string (case-insensitive)
                            if (stripos($prompt, $searchString) !== false) {
                                $matchedFiles[] = [
                                    'id' => $file->id,
                                    'filename' => $file->filename,
                                    'prompt' => $prompt
                                ];
                            }
                        }
                    }

                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);

        // Calculate percentages
        $matchCount = count($matchedFiles);
        $percentageOfTotal = $totalFiles > 0 ? round(($matchCount / $totalFiles) * 100, 2) : 0;
        $percentageOfPrompts = $filesWithPrompts > 0 ? round(($matchCount / $filesWithPrompts) * 100, 2) : 0;

        // Display results
        $this->info('Scan Results:');
        $this->info("- Total files processed: {$totalFiles}");
        $this->info("- Files with prompts: {$filesWithPrompts}");
        $this->info("- Files matching '{$searchString}': {$matchCount}");
        $this->info("- Percentage of total files: {$percentageOfTotal}%");
        $this->info("- Percentage of files with prompts: {$percentageOfPrompts}%");

        if ($matchCount > 0) {
            $this->newLine();
            $this->info('Matched files:');

//            foreach ($matchedFiles as $match) {
//                $this->line("File ID {$match['id']}: {$match['filename']}");
//                $this->line("  Prompt: " . substr($match['prompt'], 0, 100) . (strlen($match['prompt']) > 100 ? '...' : ''));
//                $this->newLine();
//            }
        }

        return Command::SUCCESS;
    }
}
