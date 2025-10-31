<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PullDatabaseFromRemoteServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:pull-from-remote-server 
                            {host : SSH host to connect to}
                            {--connection= : The local database connection to import into (defaults to default connection)}
                            {--remote-connection= : The remote database connection to backup from (defaults to default connection)}
                            {--dry-run : Show what would be done without actually doing it}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pull database from remote server via SSH and import it locally';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $force = $this->option('force');
        $connection = $this->option('connection') ?: config('database.default');
        $remoteConnection = $this->option('remote-connection') ?: config('database.default');
        $host = $this->argument('host');

        // Commands to execute
        $localBackupCommand = 'php artisan db:backup --connection='.escapeshellarg($connection);
        $remoteBackupCommand = 'ssh '.escapeshellarg($host).' "php artisan db:backup --connection='.escapeshellarg($remoteConnection).'"';
        $copyCommand = 'scp '.escapeshellarg($host).':~/storage/backups/*.sql storage/backups/';
        $importCommand = 'php artisan db:import --connection='.escapeshellarg($connection).($force ? ' --force' : '');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE: No actual sync will be performed');
            $this->info('Would execute: '.$localBackupCommand);
            $this->info('Would execute: '.$remoteBackupCommand);
            $this->info('Would execute: '.$copyCommand);
            $this->info('Would execute: '.$importCommand);
            $this->info('Dry run completed successfully');

            return Command::SUCCESS;
        }

        // Confirm before proceeding (unless --force is used)
        if (! $force && ! $this->confirm("This will overwrite your local database with data from {$host}. Are you sure you want to continue?")) {
            $this->info('Operation cancelled by user');

            return Command::SUCCESS;
        }

        // Step 1: Create local backup for safety
        $this->info('Creating local backup for safety...');
        $localBackupResult = $this->executeCommand($localBackupCommand);

        if ($localBackupResult !== 0) {
            $this->error('Failed to create local backup');

            return Command::FAILURE;
        }

        // Step 2: Create remote backup
        $this->info('Creating backup on remote server...');
        $remoteBackupResult = $this->executeCommand($remoteBackupCommand);

        if ($remoteBackupResult !== 0) {
            $this->error('Failed to create remote backup');

            return Command::FAILURE;
        }

        // Step 3: Copy remote backup to local
        $this->info('Copying remote backup to local machine...');
        $copyResult = $this->executeCommand($copyCommand);

        if ($copyResult !== 0) {
            $this->error('Failed to copy remote backup to local machine');

            return Command::FAILURE;
        }

        // Step 4: Import the latest backup (which should be the one we just copied)
        $this->info('Importing remote database backup...');
        $importResult = $this->executeCommand($importCommand);

        if ($importResult === 0) {
            $this->info('✓ Database successfully synced from remote server');
            $this->info('💡 Your original local database has been backed up in storage/backups/');

            return Command::SUCCESS;
        } else {
            $this->error('✗ Failed to import remote database backup');
            $this->error('💡 Your original local database is still intact');

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
}
