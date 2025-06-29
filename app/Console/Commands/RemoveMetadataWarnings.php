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
     * Get a generator that yields JSON files to reduce memory usage
     * Uses recursive directory traversal for extremely large directories
     *
     * @param string $directory Directory to scan
     * @return \Generator
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
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Starting metadata warnings removal process...");

        $metadataDirectory = 'metadata';
        $count = 0;
        $modified = 0;

        foreach ($this->getJsonFilesGenerator($metadataDirectory) as $file) {
            $count++;

            try {
                // Read the JSON file
                $metadataJson = Storage::get($file);
                $metadata = json_decode($metadataJson, true);

                if (!$metadata) {
                    $this->warn("Failed to parse JSON for file: {$file}");
                    continue;
                }

                $hasWarnings = false;

                // Check if there are warnings to remove
                if (isset($metadata['quality']['warnings']) && !empty($metadata['quality']['warnings'])) {
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
                $this->error("Error processing file {$file}: " . $e->getMessage());
            }
        }

        $this->info("Metadata warnings removal process completed.");
        $this->info("Total files processed: {$count}");
        $this->info("Files modified: {$modified}");
    }
}
