<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class PushDatabaseToRemoteServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:push-to-remote-server 
                            {host : SSH host to connect to}
                            {--connection= : The database connection to backup and sync (defaults to default connection)}
                            {--remote-connection= : The remote database connection to import into (defaults to default connection)}
                            {--dry-run : Show what would be done without actually doing it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Push local database to remote server via SSH';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $connection = $this->option('connection') ?: config('database.default');
        $remoteConnection = $this->option('remote-connection') ?: config('database.default');
        $host = $this->argument('host');

        // Step 1: Create local backup
        $backupCommand = 'php artisan db:backup --connection='.escapeshellarg($connection);

        // Step 2: Copy latest SQL to server app storage so import can see it
        // Use rsync with progress if available, fallback to scp
        $copyCommand = $this->buildCopyCommand($host);

        // Step 3: Run import on server in app dir; prefer art82 alias with fallback to php artisan
        // Remove verbose flag to reduce SSH debug noise
        $importCommand = 'ssh '.escapeshellarg($host).' "cd /home/wyxos/webapps/atlas && (art82 db:import --connection='.escapeshellarg($remoteConnection).' --force || php artisan db:import --connection='.escapeshellarg($remoteConnection).' --force)"';

        if ($isDryRun) {
            $this->warn('DRY RUN MODE: No actual sync will be performed');
            $this->info('Would execute: '.$backupCommand);
            $this->info('Would execute: '.$copyCommand);
            $this->info('Would execute: '.$importCommand);
            $this->info('Dry run completed successfully');

            return Command::SUCCESS;
        }

        // Confirm before proceeding
        if (! $this->confirm("This will overwrite the database on {$host}. Are you sure you want to continue?")) {
            $this->info('Operation cancelled by user');

            return Command::SUCCESS;
        }

        // Step 1: Create backup
        $this->info('Creating local backup...');
        $backupResult = $this->executeCommand($backupCommand);

        if ($backupResult !== 0) {
            $this->error('Failed to create local backup');

            return Command::FAILURE;
        }

        // Step 2: Copy to server
        $this->info('Copying backup to remote server...');
        $copyResult = $this->executeCommand($copyCommand);

        if ($copyResult !== 0) {
            $this->error('Failed to copy backup to remote server');

            return Command::FAILURE;
        }

        // Step 3: Import on server
        $this->info('Running import on remote server...');
        $importResult = $this->executeCommand($importCommand);

        if ($importResult === 0) {
            $this->info('✓ Database successfully synced to remote server');

            return Command::SUCCESS;
        } else {
            $this->error('✗ Failed to import database on remote server');

            return Command::FAILURE;
        }
    }

    /**
     * Build the copy command, preferring rsync with progress if available.
     */
    private function buildCopyCommand(string $host): string
    {
        if ($this->isRsyncAvailable()) {
            return 'rsync --progress storage/backups/*.sql '.escapeshellarg($host).':/home/wyxos/webapps/atlas/storage/backups/';
        }

        return 'scp storage/backups/*.sql '.escapeshellarg($host).':/home/wyxos/webapps/atlas/storage/backups/';
    }

    /**
     * Check if rsync is available on the system.
     */
    private function isRsyncAvailable(): bool
    {
        $process = Process::fromShellCommandline('rsync --version', base_path(), null, null, 5);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Execute a shell command and return the exit code.
     */
    private function executeCommand(string $command): int
    {
        $this->line("Executing: {$command}");

        $process = Process::fromShellCommandline($command, base_path(), null, null, null);

        try {
            $process->run(function ($type, $buffer) {
                // Stream both STDOUT and STDERR directly so progress bars and verbose output show up live
                $this->output->write($buffer);
            });
        } catch (\Throwable $e) {
            $this->error('Failed to start or run process: '.$e->getMessage());

            return 1;
        }

        return $process->getExitCode() ?? 1;
    }
}
