<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
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
                            {--dry-run : Show what would be done without actually doing it}
                            {--force : Skip confirmation prompt}
                            {--no-cleanup : Do not delete the imported SQL file after a successful import}
                            {--retention=3 : Number of recent SQL backups to retain (older files will be deleted)}';

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

        if (! $latestFile) {
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

        // Confirm before proceeding (unless --force is used)
        if (! $this->option('force') && ! $this->confirm('This will overwrite the current database. Are you sure you want to continue?')) {
            $this->info('Operation cancelled by user');

            return Command::SUCCESS;
        }

        try {
            // Get database configuration
            $config = config("database.connections.{$connection}");

            if (! $config) {
                $this->error("Database connection '{$connection}' not found");

                return Command::FAILURE;
            }

            // Accept both MySQL and MariaDB drivers
            if (! in_array($config['driver'], ['mysql', 'mariadb'], true)) {
                $this->error('This command only supports MySQL/MariaDB connections');

                return Command::FAILURE;
            }

            // Build mysql import command
            $command = $this->buildMysqlImportCommand($config, $filePath);

            $this->info('Importing SQL dump...');
            $this->line('Executing: mysql [credentials hidden]');

            $result = $this->executeCommand($command);

            if ($result === 0) {
                $fileSize = $this->formatBytes($latestFile->getSize());
                $this->info("✓ Database imported successfully from {$filePath} ({$fileSize})");

                // Cleanup imported file unless explicitly disabled
                if (! $this->option('no-cleanup')) {
                    try {
                        File::delete($filePath);
                        $this->line("Deleted imported SQL file: {$filePath}");
                    } catch (\Throwable $t) {
                        $this->warn('Warning: failed to delete imported SQL file: '.$t->getMessage());
                    }
                }

                // Enforce retention policy
                $this->pruneBackups((int) $this->option('retention'));

                return Command::SUCCESS;
            } else {
                $this->error('✗ Failed to import database');

                return Command::FAILURE;
            }

        } catch (\Exception $e) {
            $this->error('Import failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Build the mysql import command.
     */
    private function buildMysqlImportCommand(array $config, string $filePath): string
    {
        $command = $this->getMysqlPath();

        if (! empty($config['host'])) {
            $command .= ' -h '.escapeshellarg($config['host']);
        }

        if (! empty($config['port'])) {
            $command .= ' -P '.escapeshellarg($config['port']);
        }

        if (! empty($config['username'])) {
            $command .= ' -u '.escapeshellarg($config['username']);
        }

        if (! empty($config['password'])) {
            $command .= ' -p'.escapeshellarg($config['password']);
        }

        if (! empty($config['database'])) {
            $command .= ' '.escapeshellarg($config['database']);
        }

        $command .= ' < '.escapeshellarg($filePath);

        return $command;
    }

    /**
     * Get the appropriate mysql path based on the operating system.
     */
    private function getMysqlPath(): string
    {
        // Check if we're on Windows and if Herd is available
        if (PHP_OS_FAMILY === 'Windows') {
            $herdPath = getenv('USERPROFILE').'\\.config\\herd\\bin\\services\\mariadb\\10.11\\bin\\mysql.exe';

            if (file_exists($herdPath)) {
                return '"'.$herdPath.'"';
            }

            // Fallback to system PATH on Windows
            return 'mysql';
        }

        // Check if we're on macOS and if Herd is available
        if (PHP_OS_FAMILY === 'Darwin') {
            $herdPath = getenv('HOME').'/Library/Application Support/Herd/bin/mysql';

            if (file_exists($herdPath)) {
                return '"'.$herdPath.'"';
            }

            // Fallback to system PATH on macOS
            return 'mysql';
        }

        // On Linux/Unix systems, use the system PATH
        return 'mysql';
    }

    /**
     * Execute a shell command and return the exit code.
     */
    private function executeCommand(string $command): int
    {
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],  // stdin
                1 => ['pipe', 'w'],  // stdout
                2 => ['pipe', 'w'],  // stderr
            ],
            $pipes
        );

        if (! is_resource($process)) {
            $this->error('Failed to start process');

            return 1;
        }

        // Close stdin
        fclose($pipes[0]);

        // Read stdout and stderr
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if (! empty($stdout)) {
            $this->line($stdout);
        }

        if (! empty($stderr)) {
            $this->error($stderr);
        }

        return $exitCode;
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

        return round($bytes, 2).' '.$units[$pow];
    }

    /**
     * Keep only the most recent $keepCount .sql files in storage/backups.
     */
    private function pruneBackups(int $keepCount = 3): void
    {
        if ($keepCount < 0) {
            $keepCount = 0;
        }

        $backupDir = storage_path('backups');
        if (! File::exists($backupDir)) {
            return;
        }

        $files = collect(File::files($backupDir))
            ->filter(fn ($f) => str_ends_with(strtolower($f->getFilename()), '.sql'))
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->values();

        if ($files->count() <= $keepCount) {
            return;
        }

        $toDelete = $files->slice($keepCount);
        foreach ($toDelete as $file) {
            try {
                File::delete($file->getPathname());
                $this->line('Pruned old backup: '.$file->getFilename());
            } catch (\Throwable $t) {
                $this->warn('Warning: failed to delete old backup '.$file->getFilename().': '.$t->getMessage());
            }
        }
    }
}
