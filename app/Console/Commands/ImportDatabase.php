<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ImportDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:import 
                            {--connection= : The database connection to import into (defaults to default connection)}
                            {--dry-run : Show what would be done without actually doing it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import the latest SQL dump file from storage into the database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connection = $this->option('connection') ?: config('database.default');

        // Find the latest SQL file
        $files = File::files(storage_path('backups'));
        $latestFile = collect($files)->sortByDesc->getMTime()->first();

        if (!$latestFile) {
            $this->error('No backup files found in storage/backups');
            return Command::FAILURE;
        }

        $filePath = $latestFile->getPathname();
        $this->info("Importing database from: {$filePath}");
        $this->info("Using connection: {$connection}");

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN MODE: No actual import will be performed');
            $fileSize = $this->formatBytes($latestFile->getSize());
            $this->info("Would import SQL file: {$filePath} ({$fileSize})");
            return Command::SUCCESS;
        }

        // Confirm before proceeding
        if (!$this->confirm('This will overwrite the current database. Are you sure you want to continue?')) {
            $this->info('Operation cancelled by user');
            return Command::SUCCESS;
        }

        try {
            // Execute import
            DB::unprepared(file_get_contents($filePath));
            $this->info("✓ Database imported successfully from {$filePath}");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Import failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Format bytes into human readable format.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

