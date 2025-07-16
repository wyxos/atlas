<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PushDatabaseToRemote extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:push-to-remote 
                            {host : SSH host to connect to}
                            {local-path : Local database file path}
                            {remote-path : Remote database file path}
                            {--dry-run : Show what would be done without actually doing it}
                            {--no-backup : Skip creating backup on remote server}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Push local database to remote server via SSH (generic command)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $noBackup = $this->option('no-backup');
        $host = $this->argument('host');
        $localDbPath = $this->argument('local-path');
        $remoteDbPath = $this->argument('remote-path');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE: No actual sync will be performed');
        }

        $this->info('Starting database push to remote server...');

        // Check if local database exists
        if (!file_exists($localDbPath)) {
            $this->error("Local database file not found at: {$localDbPath}");
            return Command::FAILURE;
        }

        $this->info("Local database found: {$localDbPath}");
        $this->info("Target remote host: {$host}");
        $this->info("Target remote path: {$remoteDbPath}");

        // Get file size for progress indication
        $fileSize = filesize($localDbPath);
        $this->info("Database size: " . $this->formatBytes($fileSize));

        if ($isDryRun) {
            $this->info('Would execute: scp "' . $localDbPath . '" ' . $host . ':' . $remoteDbPath);
            if (!$noBackup) {
                $this->info('Would create backup: ssh ' . $host . ' "cp ' . $remoteDbPath . ' ' . $remoteDbPath . '.backup.' . date('Y-m-d_H-i-s') . '"');
            }
            $this->info('Dry run completed successfully');
            return Command::SUCCESS;
        }

        // Confirm before proceeding
        if (!$this->confirm("This will overwrite the database on {$host}. Are you sure you want to continue?")) {
            $this->info('Operation cancelled by user');
            return Command::SUCCESS;
        }

        // Create backup on remote server first (unless disabled)
        if (!$noBackup) {
            $this->info('Creating backup on remote server...');
            $backupCommand = 'ssh ' . $host . ' "cp ' . $remoteDbPath . ' ' . $remoteDbPath . '.backup.' . date('Y-m-d_H-i-s') . '"';
            
            $backupResult = $this->executeCommand($backupCommand);
            if ($backupResult !== 0) {
                $this->warn('Failed to create backup on remote server (file might not exist yet)');
            } else {
                $this->info('Backup created successfully');
            }
        }

        // Push database to remote server
        $this->info('Pushing database to remote server...');
        $syncCommand = 'scp "' . $localDbPath . '" ' . $host . ':' . $remoteDbPath;
        
        $syncResult = $this->executeCommand($syncCommand);
        
        if ($syncResult === 0) {
            $this->info('✓ Database successfully pushed to remote server');
            
            // Verify the sync
            $this->info('Verifying push...');
            $verifyCommand = 'ssh ' . $host . ' "ls -la ' . $remoteDbPath . '"';
            $verifyResult = $this->executeCommand($verifyCommand);
            
            if ($verifyResult === 0) {
                $this->info('✓ Push verified successfully');
            } else {
                $this->warn('Could not verify push');
            }
            
            return Command::SUCCESS;
        } else {
            $this->error('✗ Failed to push database to remote server');
            return Command::FAILURE;
        }
    }

    /**
     * Execute a shell command and return the exit code.
     */
    private function executeCommand(string $command): int
    {
        $this->line("Executing: {$command}");
        
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

        if (!empty($stdout)) {
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
