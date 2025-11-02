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
    protected $signature = 'db:backup {--connection= : The database connection to backup (defaults to default connection)} {--output= : Custom output path for backup file} {--retention=3 : Number of recent SQL backups to retain (older files will be deleted)}';

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
        $connection = $this->option('connection') ?: Config::get('database.default');
        $customOutput = $this->option('output');

        // Get the database configuration
        $config = Config::get("database.connections.{$connection}");

        if (! $config) {
            $this->error("Connection '{$connection}' not found in database configuration.");

            return 1;
        }

        // Create backups directory if it doesn't exist
        $backupDir = storage_path('backups');
        if (! File::exists($backupDir)) {
            File::makeDirectory($backupDir, 0755, true);
        }

        $timestamp = now()->format('Y-m-d_H-i-s');

        try {
            if ($config['driver'] === 'sqlite') {
                return $this->backupSqlite($config, $backupDir, $timestamp, $customOutput);
            } elseif (in_array($config['driver'], ['mysql', 'mariadb'])) {
                return $this->backupMysql($config, $backupDir, $timestamp, $customOutput);
            } else {
                $this->error("Backup for {$config['driver']} databases is not implemented yet.");

                return 1;
            }
        } catch (\Exception $e) {
            $this->error('Failed to create database backup: '.$e->getMessage());

            return 1;
        }
    }

    private function backupSqlite(array $config, string $backupDir, string $timestamp, ?string $customOutput): int
    {
        $databasePath = $config['database'];

        // Check if the database file exists
        if (! File::exists($databasePath)) {
            $this->error("Database file not found at: {$databasePath}");

            return 1;
        }

        // Generate backup filename with environment
        $environment = app()->environment();
        $filename = Str::of(basename($databasePath))->beforeLast('.')."_{$environment}_{$timestamp}.sqlite";
        $backupPath = $customOutput ?: "{$backupDir}/{$filename}";

        // Copy the database file
        File::copy($databasePath, $backupPath);
        $this->info("SQLite database backup created successfully: {$backupPath}");

        return 0;
    }

    private function backupMysql(array $config, string $backupDir, string $timestamp, ?string $customOutput): int
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 3306;
        $database = $config['database'];
        $username = $config['username'];
        $password = $config['password'] ?? '';

        // Generate backup filename with environment
        $environment = app()->environment();
        $filename = "{$database}_{$environment}_{$timestamp}.sql";
        $backupPath = $customOutput ?: "{$backupDir}/{$filename}";

        // Build mysqldump command with OS-appropriate path
        $mysqldumpPath = $this->getMysqldumpPath();
        $command = sprintf(
            '%s --host=%s --port=%s --user=%s --password=%s --single-transaction --routines --triggers %s > "%s"',
            $mysqldumpPath,
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($database),
            $backupPath
        );

        $this->info('Creating MySQL/MariaDB backup...');
        $exitCode = $this->executeCommand($command);

        if ($exitCode === 0) {
            $fileSize = File::size($backupPath);
            $this->info("MySQL/MariaDB database backup created successfully: {$backupPath}");
            $this->info('Backup size: '.$this->formatBytes($fileSize));

            // Enforce retention policy
            $this->pruneBackups((int) $this->option('retention'));

            return 0;
        } else {
            $this->error('Failed to create MySQL/MariaDB backup');

            return 1;
        }
    }

    private function executeCommand(string $command): int
    {
        $this->line('Executing: mysqldump [credentials hidden]');

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
            $this->error('Failed to start mysqldump process');

            return 1;
        }

        // Close stdin
        fclose($pipes[0]);

        // Read stderr (mysqldump outputs to stdout which is redirected to file)
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if (! empty($stderr)) {
            $this->error($stderr);
        }

        return $exitCode;
    }

    /**
     * Get the appropriate mysqldump path based on the operating system.
     */
    private function getMysqldumpPath(): string
    {
        // Check if we're on Windows and if Herd is available
        if (PHP_OS_FAMILY === 'Windows') {
            $herdPath = getenv('USERPROFILE').'\\.config\\herd\\bin\\services\\mariadb\\10.11\\bin\\mysqldump.exe';

            if (file_exists($herdPath)) {
                return '"'.$herdPath.'"';
            }

            // Fallback to system PATH on Windows
            return 'mysqldump';
        }

        // On Linux/Unix systems, use the system PATH
        return 'mysqldump';
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2).' '.$units[$pow];
    }

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
