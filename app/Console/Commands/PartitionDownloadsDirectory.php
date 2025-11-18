<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Support\PartitionedPathHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PartitionDownloadsDirectory extends Command
{
    protected $signature = 'files:partition-downloads
                            {--dry-run : Show what would be moved without making changes}
                            {--chunk=100 : Number of records to process per chunk}
                            {--limit= : Limit the number of files to process}
                            {--subdir-length=2 : Number of characters to use for subdirectory name}';

    protected $description = 'Partition files in downloads directory into subdirectories to improve filesystem performance';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $subdirLength = max(1, min(4, (int) $this->option('subdir-length')));

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $this->info("Partitioning downloads directory (subdirectory length: {$subdirLength})...");

        try {
            $query = File::query()
                ->where('downloaded', true)
                ->whereNotNull('path')
                ->where('path', 'LIKE', 'downloads/%')
                ->where('path', 'NOT LIKE', 'downloads/%/%') // Exclude already partitioned files
                ->orderBy('id');

            $total = (clone $query)->count();
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'No connection could be made') || str_contains($e->getMessage(), 'Connection refused')) {
                $this->error('Database connection failed. Please ensure your database server is running.');
                $this->line('Error: '.$e->getMessage());

                return Command::FAILURE;
            }

            throw $e;
        }

        if ($limit) {
            $query->limit($limit);
            $total = min($total, $limit);
        }

        if ($total === 0) {
            $this->info('No files found to partition.');

            return Command::SUCCESS;
        }

        $this->info("Found {$total} file(s) to process");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $stats = [
            'processed' => 0,
            'moved' => 0,
            'skipped' => 0,
            'failed' => 0,
            'missing' => 0,
        ];

        $query->chunkById($chunk, function ($files) use ($dryRun, $subdirLength, &$stats, $bar) {
            foreach ($files as $file) {
                try {
                    $stats['processed']++;
                    $result = $this->partitionFile($file, $subdirLength, $dryRun);
                    $stats[$result]++;
                } catch (\Throwable $e) {
                    $this->newLine();
                    $this->error("Failed to process file ID {$file->id}: {$e->getMessage()}");
                    $stats['failed']++;
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Processed: {$stats['processed']}");
        $this->info("Moved: {$stats['moved']}");
        $this->info("Skipped: {$stats['skipped']}");
        $this->info("Missing: {$stats['missing']}");
        if ($stats['failed'] > 0) {
            $this->warn("Failed: {$stats['failed']}");
        }

        if ($dryRun) {
            $this->info('Dry run complete. No files were moved.');
        }

        return Command::SUCCESS;
    }

    protected function partitionFile(File $file, int $subdirLength, bool $dryRun): string
    {
        // Get the filename from path
        $filename = basename($file->path);
        if ($filename === '' || $filename === $file->path) {
            // Path doesn't have a directory separator, use filename field
            $filename = $file->filename ?? Str::random(40);
        }

        // Generate partitioned path
        $newPath = PartitionedPathHelper::generatePath($filename, $subdirLength);

        // Check if file already exists at new location (already partitioned)
        if ($file->path === $newPath) {
            return 'skipped';
        }

        // Find which disks have the file
        $disksWithFile = collect(['atlas_app', 'atlas'])->filter(function (string $disk) use ($file) {
            return Storage::disk($disk)->exists($file->path);
        })->values();

        if ($disksWithFile->isEmpty()) {
            return 'missing';
        }

        if ($dryRun) {
            $diskList = $disksWithFile->implode(', ');
            $this->line("Would move: File ID {$file->id} from {$file->path} to {$newPath} (disks: {$diskList})");

            return 'skipped';
        }

        // Move file on each disk
        foreach ($disksWithFile as $disk) {
            $storage = Storage::disk($disk);

            // Ensure subdirectory exists
            $subdir = PartitionedPathHelper::getSubdirectory($filename, $subdirLength);
            $subdirPath = "downloads/{$subdir}";
            if (! $storage->exists($subdirPath)) {
                $storage->makeDirectory($subdirPath);
            }

            // Move the file
            if (! $storage->move($file->path, $newPath)) {
                throw new \RuntimeException("Failed to move file on disk {$disk} from {$file->path} to {$newPath}");
            }
        }

        // Update database record
        $file->update(['path' => $newPath]);

        // Update search index
        try {
            $file->refresh();
            $file->searchable();
        } catch (\Throwable $e) {
            // Log but don't fail - search index update is best-effort
            \Illuminate\Support\Facades\Log::warning('PartitionDownloadsDirectory: searchable failed', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
        }

        return 'moved';
    }
}
