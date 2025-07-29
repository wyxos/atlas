<?php

namespace App\Console\Commands;

use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GeneralFixesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'general:fixes {--fix=* : Specific fixes to run (mp4-types)}'
                         . ' {--dry-run : Show what would be fixed without making changes}'
                         . ' {--limit=100 : Maximum number of records to process per fix}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run general database and data fixes';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $fixes = $this->option('fix');
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        // If no specific fixes requested, run all available fixes
        if (empty($fixes)) {
            $fixes = ['mp4-types'];
        }

        $this->info('General Fixes Command');
        $this->info('==================');
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        foreach ($fixes as $fix) {
            $this->newLine();
            $this->info("Running fix: {$fix}");
            
            switch ($fix) {
                case 'mp4-types':
                    $this->fixMp4FileTypes($dryRun, $limit);
                    break;
                    
                default:
                    $this->error("Unknown fix: {$fix}");
                    break;
            }
        }

        $this->newLine();
        $this->info('All fixes completed!');
    }

    /**
     * Fix MP4 files that have incorrect extension or MIME type.
     */
    private function fixMp4FileTypes(bool $dryRun, int $limit): void
    {
        $this->info('Fixing MP4 file types and extensions...');

        // Find files with MP4 in URL but incorrect ext or mime_type
        $files = File::where('url', 'like', '%.mp4%')
            ->where(function ($query) {
                $query->where('ext', '!=', 'mp4')
                      ->orWhere('mime_type', '!=', 'video/mp4');
            })
            ->limit($limit)
            ->get();

        if ($files->isEmpty()) {
            $this->info('No MP4 files found that need fixing.');
            return;
        }

        $this->info("Found {$files->count()} MP4 files that need fixing.");

        $fixedCount = 0;
        $errors = [];

        foreach ($files as $file) {
            try {
                $changes = [];
                $originalExt = $file->ext;
                $originalMimeType = $file->mime_type;

                // Check if extension needs fixing
                if ($file->ext !== 'mp4') {
                    $changes[] = "ext: '{$originalExt}' → 'mp4'";
                }

                // Check if MIME type needs fixing
                if ($file->mime_type !== 'video/mp4') {
                    $changes[] = "mime_type: '{$originalMimeType}' → 'video/mp4'";
                }

                if (!empty($changes)) {
                    $changesList = implode(', ', $changes);
                    
                    if ($dryRun) {
                        $this->line("[DRY RUN] File ID {$file->id}: {$changesList}");
                    } else {
                        // Make the actual changes
                        $file->update([
                            'ext' => 'mp4',
                            'mime_type' => 'video/mp4'
                        ]);
                        
                        $this->line("Fixed File ID {$file->id}: {$changesList}");
                    }
                    
                    $fixedCount++;
                }
            } catch (\Exception $e) {
                $errors[] = "File ID {$file->id}: {$e->getMessage()}";
            }
        }

        // Summary
        if ($dryRun) {
            $this->info("[DRY RUN] Would fix {$fixedCount} files.");
        } else {
            $this->info("Successfully fixed {$fixedCount} files.");
        }

        if (!empty($errors)) {
            $this->error('Errors encountered:');
            foreach ($errors as $error) {
                $this->error("  - {$error}");
            }
        }
    }

    /**
     * Get the correct MIME type for a file extension.
     */
    private function getMimeTypeForExtension(string $extension): string
    {
        $extension = strtolower($extension);
        
        switch ($extension) {
            case 'jpeg':
            case 'jpg':
                return 'image/jpeg';
            case 'png':
                return 'image/png';
            case 'gif':
                return 'image/gif';
            case 'webp':
                return 'image/webp';
            case 'mp4':
                return 'video/mp4';
            case 'avi':
                return 'video/x-msvideo';
            case 'mov':
                return 'video/quicktime';
            case 'webm':
                return 'video/webm';
            default:
                return 'application/octet-stream';
        }
    }
}
