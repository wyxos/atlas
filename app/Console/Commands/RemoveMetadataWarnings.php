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
    protected $signature = 'files:remove-metadata-warnings {--file= : Process only the specified metadata file path}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cycle through all metadata JSON files and remove warnings';

    /**
     * Get a generator that yields JSON files to reduce memory usage
     * Uses recursive directory traversal for extremely large directories
     *
     * @param  string  $directory  Directory to scan
     */
    protected function getJsonFilesGenerator(string $directory): \Generator
    {
        $files = Storage::files($directory);

        foreach ($files as $file) {
            // Only process JSON files
            if (pathinfo($file, PATHINFO_EXTENSION) === 'json') {
                yield $file;
            }
        }

        // Recursively process subdirectories
        $directories = Storage::directories($directory);
        foreach ($directories as $subDirectory) {
            yield from $this->getJsonFilesGenerator($subDirectory);
        }
    }

    /**
     * Process a single JSON file to remove warnings
     *
     * @param  string  $file  File path
     * @param  int  $count  Counter for processed files
     * @param  int  $modified  Counter for modified files
     */
    protected function processJsonFile(string $file, int &$count, int &$modified): void
    {
        $count++;

        try {
            // Read the JSON file
            $metadataJson = Storage::get($file);
            $metadata = json_decode($metadataJson, true);

            if (! $metadata) {
                $this->warn("Failed to parse JSON for file: {$file}");

                return;
            }

            $hasWarnings = false;

            // Check if there are warnings to remove
            if (isset($metadata['quality']['warnings']) && ! empty($metadata['quality']['warnings'])) {
                // Remove warnings by setting to empty array
                $metadata['quality']['warnings'] = [];
                $hasWarnings = true;
            }

            // Only update the file if warnings were removed
            if ($hasWarnings) {
                // Write the updated JSON back to the file
                Storage::put($file, json_encode($metadata, JSON_PRETTY_PRINT));
                $modified++;
                $this->info("Removed warnings from file: {$file}");
            }
        } catch (\Exception $e) {
            $this->error("Error processing file {$file}: ".$e->getMessage());
        }
    }

    public function handle()
    {
        $this->info('Starting metadata warnings removal process...');

        $metadataDirectory = 'metadata';
        $count = 0;
        $modified = 0;
        $filePath = $this->option('file');

        // If a specific file is provided, only process that file
        if ($filePath) {
            $this->info("Processing only file: {$filePath}");

            // Check if the file exists
            if (! Storage::exists($filePath)) {
                $this->error("File not found: {$filePath}");

                return Command::FAILURE;
            }

            // Check if it's a JSON file
            if (pathinfo($filePath, PATHINFO_EXTENSION) !== 'json') {
                $this->error("File is not a JSON file: {$filePath}");

                return Command::FAILURE;
            }

            $this->processJsonFile($filePath, $count, $modified);
        } else {
            // Process all JSON files in the metadata directory
            foreach ($this->getJsonFilesGenerator($metadataDirectory) as $file) {
                $this->processJsonFile($file, $count, $modified);
            }
        }

        $this->info('Metadata warnings removal process completed.');
        $this->info("Total files processed: {$count}");
        $this->info("Files modified: {$modified}");

        return Command::SUCCESS;
    }
}
