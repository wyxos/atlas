<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class BackupDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:backup {--connection=sqlite : The database connection to backup}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a backup of the database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connection = $this->option('connection');

        // Get the database configuration
        $config = Config::get("database.connections.{$connection}");

        if (! $config) {
            $this->error("Connection '{$connection}' not found in database configuration.");

            return 1;
        }

        // For SQLite, we need to copy the database file
        if ($config['driver'] === 'sqlite') {
            $databasePath = $config['database'];

            // Check if the database file exists
            if (! File::exists($databasePath)) {
                $this->error("Database file not found at: {$databasePath}");

                return 1;
            }

            // Create backups directory if it doesn't exist
            $backupDir = storage_path('backups');
            if (! File::exists($backupDir)) {
                File::makeDirectory($backupDir, 0755, true);
            }

            // Generate backup filename with timestamp
            $timestamp = now()->format('Y-m-d_H-i-s');
            $filename = Str::of(basename($databasePath))->beforeLast('.')."_{$timestamp}.sqlite";
            $backupPath = "{$backupDir}/{$filename}";

            // Copy the database file
            try {
                File::copy($databasePath, $backupPath);
                $this->info("Database backup created successfully: {$backupPath}");

                return 0;
            } catch (\Exception $e) {
                $this->error('Failed to create database backup: '.$e->getMessage());

                return 1;
            }
        } else {
            $this->error("Backup for {$config['driver']} databases is not implemented yet.");

            return 1;
        }
    }
}
