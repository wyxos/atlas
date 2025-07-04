<?php

namespace App\Console\Commands;

use App\Jobs\ProcessFileHash;
use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class ScanAtlasFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'atlas:scan-files {--dry-run : Show what would be done without making changes} {--chunk=100 : Number of files to process per chunk}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan the atlas disk for all files and create them in the database if they don\'t exist';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Increase memory limit for large file processing
        ini_set('memory_limit', '1G');

        $isDryRun = $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk');

        $this->info('Scanning atlas disk for files...');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made to the database');
        }

        $atlasPath = Storage::disk('atlas')->path('');

        if (!is_dir($atlasPath)) {
            $this->error("Atlas storage path does not exist: {$atlasPath}");
            return Command::FAILURE;
        }

        $this->info("Scanning directory: {$atlasPath}");

        // Get all files from the atlas disk
        $files = $this->scanDirectory($atlasPath);
        $totalFiles = count($files);

        if ($totalFiles === 0) {
            $this->info('No files found in atlas directory.');
            return Command::SUCCESS;
        }

        $this->info("Found {$totalFiles} files to process.");

        $bar = $this->output->createProgressBar($totalFiles);
        $bar->start();

        $newFilesCount = 0;
        $existingFilesCount = 0;
        $errorCount = 0;

        // Process files in chunks
        $chunks = array_chunk($files, $chunkSize);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $filePath) {
                try {
                    $result = $this->processFile($filePath, $atlasPath, $isDryRun);

                    if ($result['created']) {
                        $newFilesCount++;
                    } else {
                        $existingFilesCount++;
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->error("Error processing file {$filePath}: " . $e->getMessage());
                }

                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Atlas file scan completed:');
        $this->info("- Total files scanned: {$totalFiles}");
        $this->info("- New files " . ($isDryRun ? 'would be ' : '') . "created: {$newFilesCount}");
        $this->info("- Existing files found: {$existingFilesCount}");
        $this->info("- Errors: {$errorCount}");

        return Command::SUCCESS;
    }

    /**
     * Scan directory recursively for all files.
     */
    private function scanDirectory(string $path): array
    {
        $files = [];

        try {
            // Check if directory exists and is readable
            if (!is_dir($path) || !is_readable($path)) {
                return $files;
            }

            // Get paths to exclude
            $coversPath = Storage::disk('atlas')->path('covers');
            $metadataPath = Storage::disk('atlas')->path('metadata');

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $filePath = $file->getPathname();

                    // Skip files in covers and metadata folders
                    if (str_starts_with($filePath, $coversPath) || str_starts_with($filePath, $metadataPath)) {
                        continue;
                    }

                    $files[] = $filePath;
                }
            }
        } catch (\Exception $e) {
            // Log the error but don't fail the command for empty directories
            $this->warn("Warning while scanning directory: " . $e->getMessage());
        }

        return $files;
    }

    /**
     * Process a single file and create database entry if it doesn't exist.
     */
    private function processFile(string $filePath, string $atlasPath, bool $isDryRun): array
    {
        // Get relative path from atlas root
        $relativePath = str_replace($atlasPath, '', $filePath);
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

        // Check if file already exists in database
        $existingFile = File::where('path', $relativePath)
            ->first();

        if ($existingFile) {
            return ['created' => false, 'file' => $existingFile];
        }

        if ($isDryRun) {
            return ['created' => true, 'file' => null];
        }

        // Get file information
        $fileInfo = new SplFileInfo($filePath);
        $filename = $fileInfo->getFilename();
        $extension = strtolower($fileInfo->getExtension());
        $size = $fileInfo->getSize();

        // Determine MIME type
        $mimeType = $this->getMimeType($filePath, $extension);

        // Extract title from filename (remove extension)
        $title = pathinfo($filename, PATHINFO_FILENAME);

        // Dispatch job to handle file hashing and database creation
        ProcessFileHash::dispatch(
            $filePath,
            $relativePath,
            $filename,
            $extension,
            $size,
            $mimeType,
            $title
        );

        return ['created' => true, 'file' => null];
    }

    /**
     * Get MIME type for a file.
     */
    private function getMimeType(string $filePath, string $extension): ?string
    {
        // Fallback to extension-based MIME type detection first for consistency
        $mimeTypes = [
            // Audio
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'flac' => 'audio/flac',
            'aac' => 'audio/aac',
            'ogg' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'wma' => 'audio/x-ms-wma',

            // Video
            'mp4' => 'video/mp4',
            'avi' => 'video/x-msvideo',
            'mkv' => 'video/x-matroska',
            'mov' => 'video/quicktime',
            'wmv' => 'video/x-ms-wmv',
            'flv' => 'video/x-flv',
            'webm' => 'video/webm',

            // Images
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',

            // Documents
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];

        // If we have a known extension, use it
        if ($extension && isset($mimeTypes[$extension])) {
            return $mimeTypes[$extension];
        }

        // For files without extensions, return application/octet-stream
        if (!$extension) {
            return 'application/octet-stream';
        }

        // Try to get MIME type using finfo for unknown extensions
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mimeType = finfo_file($finfo, $filePath);
                finfo_close($finfo);
                if ($mimeType && $mimeType !== 'application/octet-stream') {
                    return $mimeType;
                }
            }
        }

        return 'application/octet-stream';
    }
}
