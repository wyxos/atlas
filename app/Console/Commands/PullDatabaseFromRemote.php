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
                            {local-path : Local database file path}
                            {remote-path : Remote database file path}
                            {--dry-run : Show what would be done without actually doing it}
                            {--no-backup : Skip creating backup of local database}';

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
        $noBackup = $this->option('no-backup');
        $host = $this->argument('host');
        $localDbPath = $this->argument('local-path');
        $remoteDbPath = $this->argument('remote-path');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE: No actual sync will be performed');
        }

        $this->info('Starting database pull from remote server...');
        $this->info("Source remote host: {$host}");
        $this->info("Source remote path: {$remoteDbPath}");
        $this->info("Target local path: {$localDbPath}");

        if ($isDryRun) {
            $this->info('Would execute: scp ' . $host . ':' . $remoteDbPath . ' "' . $localDbPath . '"');
            if (!$noBackup && file_exists($localDbPath)) {
                $this->info('Would create backup: cp "' . $localDbPath . '" "' . $localDbPath . '.backup.' . date('Y-m-d_H-i-s') . '"');
            }
            $this->info('Dry run completed successfully');
            return Command::SUCCESS;
        }

        // Check if remote database exists
        $this->info('Checking if remote database exists...');
        $checkCommand = 'ssh ' . $host . ' "test -f ' . $remoteDbPath . ' && echo exists || echo not_found"';
        $checkResult = $this->executeCommand($checkCommand, true);
        
        if ($checkResult !== 0) {
            $this->error('Failed to check remote database existence');
            return Command::FAILURE;
        }

        // Confirm before proceeding
        if (!$this->confirm('This will overwrite your local database. Are you sure you want to continue?')) {
            $this->info('Operation cancelled by user');
            return Command::SUCCESS;
        }

        // Create backup of local database if it exists and backup is not disabled
        if (!$noBackup && file_exists($localDbPath)) {
            $this->info('Creating backup of local database...');
            $backupPath = $localDbPath . '.backup.' . date('Y-m-d_H-i-s');
            
            if (copy($localDbPath, $backupPath)) {
                $this->info("✓ Local database backed up to: {$backupPath}");
            } else {
                $this->error('Failed to create backup of local database');
                return Command::FAILURE;
            }
        }

        // Get remote database size for progress indication
        $this->info('Getting remote database information...');
        $sizeCommand = 'ssh ' . $host . ' "ls -la ' . $remoteDbPath . ' | awk \'{print $5}\'"';
        $sizeResult = $this->executeCommand($sizeCommand, true);
        
        if ($sizeResult === 0) {
            $this->info('Remote database found');
        }

        // Pull database from remote server
        $this->info('Pulling database from remote server...');
        $syncCommand = 'scp ' . $host . ':' . $remoteDbPath . ' "' . $localDbPath . '"';
        
        $syncResult = $this->executeCommand($syncCommand);
        
        if ($syncResult === 0) {
            $this->info('✓ Database successfully pulled from remote server');
            
            // Verify the sync
            $this->info('Verifying pull...');
            if (file_exists($localDbPath)) {
                $fileSize = filesize($localDbPath);
                $this->info("✓ Local database verified - Size: " . $this->formatBytes($fileSize));
                
                // Test database integrity
                $this->info('Testing database integrity...');
                $integrityCommand = 'sqlite3 "' . $localDbPath . '" "PRAGMA integrity_check;"';
                $integrityResult = $this->executeCommand($integrityCommand, true);
                
                if ($integrityResult === 0) {
                    $this->info('✓ Database integrity check passed');
                } else {
                    $this->warn('Database integrity check failed or could not be performed');
                }
            } else {
                $this->error('Local database file not found after pull');
                return Command::FAILURE;
            }
            
            return Command::SUCCESS;
        } else {
            $this->error('✗ Failed to pull database from remote server');
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
