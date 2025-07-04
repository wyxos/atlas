<?php

namespace App\Console\Commands;

use App\Models\Cover;
use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ProcessCovers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:process-covers {--file= : Process only the specified file ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process cover images from file metadata';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting cover processing...');

        $fileId = $this->option('file');
        $processed = 0;
        $duplicates = 0;

        // If a specific file ID is provided, only process that file
        if ($fileId) {
            $this->info("Processing only file with ID: {$fileId}");
            $file = File::has('covers')->with('covers')->where('id', $fileId)->first();

            if ($file) {
                $this->processFile($file, $processed, $duplicates);
            } else {
                $this->error("File with ID {$fileId} not found or has no covers.");

                return Command::FAILURE;
            }
        } else {
            // Get all files that have covers
            $files = File::has('covers')->with('covers')->get();
            $this->info("Found {$files->count()} files with cover art.");

            foreach ($files as $file) {
                $this->processFile($file, $processed, $duplicates);
            }
        }

        $this->info("Processed {$processed} covers.");
        $this->info("Found {$duplicates} duplicate covers.");

        return Command::SUCCESS;
    }

    /**
     * Process a single file's covers
     *
     * @param  int  $processed
     * @param  int  $duplicates
     */
    protected function processFile(File $file, &$processed, &$duplicates): void
    {
        if (! $file || $file->covers->isEmpty()) {
            return;
        }

        $cover = $file->covers->first();
        $coverPath = $cover->path;
        $this->info("Processing cover for file: {$file->path}");

        // Check if the cover file exists
        if (! Storage::disk('public')->exists($coverPath)) {
            $this->warn("Cover file not found: {$coverPath}");

            return;
        }

        // Get the file content and create a hash
        $fileContent = Storage::disk('public')->get($coverPath);
        $hash = md5($fileContent);

        // Get file extension
        $extension = pathinfo($coverPath, PATHINFO_EXTENSION);

        // Check if a cover with this hash already exists (other than the current cover)
        $existingCover = Cover::where('hash', $hash)
            ->where('id', '!=', $cover->id)
            ->first();

        if ($existingCover) {
            $this->info("Found duplicate cover with hash: {$hash}");
            $duplicates++;

            // Associate the existing cover with the file
            $file->covers()->syncWithoutDetaching([$existingCover->id]);

            // Remove the association with the current cover
            $file->covers()->detach($cover->id);

            // Delete the duplicate file
            Storage::disk('public')->delete($coverPath);
            $this->info("Deleted duplicate cover: {$coverPath}");
        } else {
            // Rename the file to follow the pattern cover-{coverId}.{ext}
            $newPath = "covers/cover-{$cover->id}.{$extension}";

            // Ensure the covers directory exists
            if (! Storage::disk('public')->exists('covers')) {
                Storage::disk('public')->makeDirectory('covers');
            }

            // Move the file to the new location
            if (Storage::disk('public')->move($coverPath, $newPath)) {
                // Update the cover record with the new path and hash
                $cover->path = $newPath;
                $cover->hash = $hash;
                $cover->save();

                $this->info("Updated cover path to: {$newPath}");
                $processed++;
            } else {
                $this->error("Failed to move cover file from {$coverPath} to {$newPath}");
            }
        }
    }
}
