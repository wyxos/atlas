<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RemoveMetadataWarnings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:remove-metadata-warnings';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cycle through all metadata JSON files and remove warnings';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting to remove warnings from metadata JSON files...');

        // Check if metadata directory exists
        if (!Storage::exists('metadata')) {
            $this->error('Metadata directory not found!');
            return 1;
        }

        // Get all JSON files in the metadata directory
        $files = Storage::files('metadata');
        $jsonFiles = array_filter($files, function ($file) {
            return pathinfo($file, PATHINFO_EXTENSION) === 'json';
        });

        if (empty($jsonFiles)) {
            $this->info('No metadata JSON files found.');
            return 0;
        }

        $this->info('Found ' . count($jsonFiles) . ' metadata JSON files.');

        $processedCount = 0;
        $modifiedCount = 0;

        foreach ($jsonFiles as $file) {
            $this->line("Processing file: {$file}");

            // Read the JSON file
            $content = Storage::get($file);
            $metadata = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->warn("Error parsing JSON in file: {$file}. Skipping.");
                continue;
            }

            $processedCount++;
            $modified = false;

            // Check if the file has warnings in the quality section
            if (isset($metadata['quality']['warnings']) && !empty($metadata['quality']['warnings'])) {
                // Remove warnings
                $metadata['quality']['warnings'] = [];
                $modified = true;
                $modifiedCount++;
                $this->line("  - Removed warnings from file: {$file}");
            }

            // Save the modified JSON back to the file if it was modified
            if ($modified) {
                Storage::put($file, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        }

        $this->info("Processed {$processedCount} files.");
        $this->info("Modified {$modifiedCount} files to remove warnings.");
        $this->info('Operation completed successfully.');

        return 0;
    }
}
