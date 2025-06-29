<?php

namespace App\Console\Commands;

use App\Models\Cover;
use App\Models\File;
use App\Models\FileMetadata;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessCovers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:process-covers';

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

        // Get all file metadata that contains a cover path
        $fileMetadata = FileMetadata::whereRaw("JSON_EXTRACT(payload, '$.cover_art_path') IS NOT NULL")
            ->with('file')
            ->get();

        $this->info("Found {$fileMetadata->count()} files with cover art.");

        $processed = 0;
        $duplicates = 0;

        foreach ($fileMetadata as $metadata) {
            $this->processMetadata($metadata, $processed, $duplicates);
        }

        $this->info("Processed {$processed} covers.");
        $this->info("Found {$duplicates} duplicate covers.");

        return Command::SUCCESS;
    }

    /**
     * Process a single metadata record
     *
     * @param FileMetadata $metadata
     * @param int $processed
     * @param int $duplicates
     * @return void
     */
    protected function processMetadata(FileMetadata $metadata, &$processed, &$duplicates): void
    {
        $file = $metadata->file;
        $payload = $metadata->payload;

        if (!$file || !isset($payload['cover_art_path'])) {
            return;
        }

        $coverPath = $payload['cover_art_path'];
        $this->info("Processing cover for file: {$file->path}");

        // Check if the cover file exists
        if (!Storage::disk('public')->exists($coverPath)) {
            $this->warn("Cover file not found: {$coverPath}");
            return;
        }

        // Get the file content and create a hash
        $fileContent = Storage::disk('public')->get($coverPath);
        $hash = md5($fileContent);

        // Get file extension
        $extension = pathinfo($coverPath, PATHINFO_EXTENSION);

        // Check if a cover with this hash already exists
        $existingCover = Cover::where('hash', $hash)->first();

        if ($existingCover) {
            $this->info("Found duplicate cover with hash: {$hash}");
            $duplicates++;

            // Associate the existing cover with the file
            $file->covers()->syncWithoutDetaching([$existingCover->id]);

            // Update the metadata to point to the existing cover
            $payload['cover_art_path'] = $existingCover->path;
            $metadata->payload = $payload;
            $metadata->save();

            // Delete the duplicate file
            Storage::disk('public')->delete($coverPath);
            $this->info("Deleted duplicate cover: {$coverPath}");
        } else {
            // Create a new cover record
            DB::beginTransaction();

            try {
                $cover = Cover::create([
                    'hash' => $hash,
                    'path' => $coverPath, // Temporary path
                ]);

                // Rename the file to follow the pattern cover-{coverId}.{ext}
                $newPath = "covers/cover-{$cover->id}.{$extension}";

                // Ensure the covers directory exists
                if (!Storage::disk('public')->exists('covers')) {
                    Storage::disk('public')->makeDirectory('covers');
                }

                // Move the file to the new location
                if (Storage::disk('public')->move($coverPath, $newPath)) {
                    // Update the cover record with the new path
                    $cover->path = $newPath;
                    $cover->save();

                    // Associate the cover with the file
                    $file->covers()->syncWithoutDetaching([$cover->id]);

                    // Update the metadata to point to the new path
                    $payload['cover_art_path'] = $newPath;
                    $metadata->payload = $payload;
                    $metadata->save();

                    $this->info("Created new cover: {$newPath}");
                    $processed++;

                    DB::commit();
                } else {
                    $this->error("Failed to move cover file from {$coverPath} to {$newPath}");
                    DB::rollBack();
                }
            } catch (\Exception $e) {
                $this->error("Error processing cover: " . $e->getMessage());
                DB::rollBack();
            }
        }
    }
}
