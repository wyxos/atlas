<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PullDatabaseFromRemote extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:pull-from-remote 
                            {host : SSH host to connect to}
                            {--connection= : The local database connection to import into (defaults to default connection)}
                            {--remote-connection= : The remote database connection to backup (defaults to default connection)}
                            {--dry-run : Show what would be done without actually doing it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pull database from remote server to local machine via SSH (generic command)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $connection = $this->option('connection') ?: config('database.default');
        $remoteConnection = $this->option('remote-connection') ?: config('database.default');
        $host = $this->argument('host');

        $remoteBackupCommand = 'ssh ' . $host . ' "php artisan db:backup --connection=' . escapeshellarg($remoteConnection) . '"';
        $pullBackupCommand = 'scp ' . $host . ':storage/backups/*.sql storage/backups/';
        $importCommand = 'php artisan db:import storage/backups/`ls -t storage/backups/ | head -1` --connection=' . escapeshellarg($connection);

        if ($isDryRun) {
            $this->warn('DRY RUN MODE: No actual sync will be performed');
            $this->info('Would execute: ' . $remoteBackupCommand);
            $this->info('Would execute: ' . $pullBackupCommand);
            $this->info('Would execute: ' . $importCommand);
            $this->info('Dry run completed successfully');
            return Command::SUCCESS;
        }

        // Confirm before proceeding
        if (!$this->confirm('This will overwrite your local database. Are you sure you want to continue?')) {
            $this->info('Operation cancelled by user');
            return Command::SUCCESS;
        }

        // Run backup command on remote server
        $this->info('Creating backup on remote server...');
        $backupResult = $this->executeCommand($remoteBackupCommand);

        if ($backupResult !== 0) {
            $this->error('Failed to create backup on remote server');
            return Command::FAILURE;
        }

        $this->info('Pulling backup from remote server...');
        
        // Pull backup from remote server
        $pullResult = $this->executeCommand($pullBackupCommand);

        if ($pullResult !== 0) {
            $this->error('Failed to pull backup from remote server');
            return Command::FAILURE;
        }

        $this->info('Importing database locally...');
        $importResult = $this->executeCommand($importCommand);

        if ($importResult === 0) {
            $this->info('✓ Database successfully imported locally');
            return Command::SUCCESS;
        } else {
            $this->error('✗ Failed to import database locally');
            return Command::FAILURE;
        }
    }

    /**
     * Execute a shell command and return the exit code.
     */
    private function executeCommand(string $command, bool $quiet = false): int
    {
        if (!$quiet) {
            $this->line("Executing: {$command}");
        }
        
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],  // stdin
                1 => ['pipe', 'w'],  // stdout
                2 => ['pipe', 'w'],  // stderr
            ],
            $pipes
        );

        if (!is_resource($process)) {
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

        if (!empty($stdout) && !$quiet) {
            $this->line($stdout);
        }

        if (!empty($stderr)) {
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
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
