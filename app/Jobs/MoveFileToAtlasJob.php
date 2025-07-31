<?php

namespace App\Jobs;

use App\Models\File;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MoveFileToAtlasJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The file to be moved.
     */
    public File $file;

    /**
     * Create a new job instance.
     */
    public function __construct(File $file)
    {
        $this->file = $file;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Skip files that don't have a path or don't exist
        if (empty($this->file->path) || ! file_exists($this->file->path)) {
            return;
        }

        // Sanitize the file path to remove problematic Unicode characters
        $sanitizedPath = $this->sanitizePath($this->file->path);
        if ($sanitizedPath !== $this->file->path) {
            // Update the file record with sanitized path
            $this->file->path = $sanitizedPath;
            $this->file->save();
        }

        // Generate a new path on the atlas disk
        $filename = $this->file->filename ?: basename($this->file->path);
        $extension = $this->file->ext ?: pathinfo($this->file->path, PATHINFO_EXTENSION);
        $newFilename = $filename;

        if (! empty($extension) && ! Str::endsWith($newFilename, '.'.$extension)) {
            $newFilename .= '.'.$extension;
        }

        // Maintain the original file path structure
        $originalPath = $this->file->path;

        // Normalize the path to use forward slashes
        $originalPath = str_replace('\\', '/', $originalPath);

        // Handle absolute paths by removing drive letters and leading slashes
        if (preg_match('/^[a-zA-Z]:/', $originalPath)) {
            // Remove drive letter (e.g., C:)
            $originalPath = preg_replace('/^[a-zA-Z]:/', '', $originalPath);
        }

        $originalDirectory = dirname($originalPath);

        // If the original path is just a filename with no directory, use the filename directly
        if ($originalDirectory === '.' || $originalDirectory === '') {
            $newPath = $newFilename;
        } else {
            // Otherwise, maintain the directory structure
            $newPath = $originalDirectory.'/'.$newFilename;
        }

        // Remove any leading slashes to avoid storing files at the root of the disk
        $newPath = ltrim($newPath, '/');

        // Check if the file already exists in the atlas storage
        if (Storage::disk('atlas')->exists($newPath)) {
            return;
        }

        // Stream the file to atlas storage to handle large files efficiently
        $sourceStream = fopen($this->file->path, 'r');
        if (! $sourceStream) {
            throw new Exception("Could not open source file for reading: {$this->file->path}");
        }

        try {
            // Use Laravel's streaming put method for memory-efficient file copying
            Storage::disk('atlas')->put($newPath, $sourceStream);
        } finally {
            // Always close the stream, even if an exception occurs
            fclose($sourceStream);
        }

        // Update the file record with the new path
        $oldPath = $this->file->path;
        $this->file->path = $newPath;
        $this->file->save();

        // Delete the original file with robust error handling
        $this->deleteOriginalFileWithRetry($oldPath);
    }

    /**
     * Delete the original file with retry logic and comprehensive error handling.
     */
    private function deleteOriginalFileWithRetry(string $filePath, int $maxRetries = 3): void
    {
        if (! file_exists($filePath)) {
            return;
        }

        $retryCount = 0;
        $lastError = null;

        while ($retryCount < $maxRetries) {
            try {
                // Check if file is readable and writable
                if (! is_readable($filePath) || ! is_writable($filePath)) {
                    throw new Exception("File is not readable or writable: {$filePath}");
                }

                // Attempt to delete the file
                if (unlink($filePath)) {
                    return;
                }

                throw new Exception("unlink() returned false for: {$filePath}");
            } catch (Exception $e) {
                $lastError = $e;
                $retryCount++;

                // If this isn't the last retry, wait before trying again
                if ($retryCount < $maxRetries) {
                    // Exponential backoff: 1s, 2s, 4s
                    $delay = pow(2, $retryCount - 1);
                    sleep($delay);

                    // Try to clear any file locks by running garbage collection
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }
            }
        }

        // If we've exhausted all retries, mark for manual cleanup

        // Mark the file for manual cleanup by adding a note to the file record
        $this->markFileForManualCleanup($filePath, $lastError ? $lastError->getMessage() : 'Unknown error');
    }

    /**
     * Mark a file for manual cleanup by updating the file record.
     */
    private function markFileForManualCleanup(string $originalPath, string $error): void
    {
        try {
            // Add a note to the file record about the cleanup needed
            $cleanupNote = "MANUAL CLEANUP NEEDED: Original file could not be deleted at '{$originalPath}'. Error: {$error}";

            // If the file has a notes field, append to it; otherwise create a new field or log it
            if ($this->file->hasAttribute('notes')) {
                $existingNotes = $this->file->notes ?? '';
                $this->file->notes = $existingNotes.($existingNotes ? "\n" : '').$cleanupNote;
                $this->file->save();
            }
        } catch (Exception $e) {
            // Failed to mark file for manual cleanup
        }
    }

    /**
     * Sanitize file path by removing problematic Unicode characters.
     */
    private function sanitizePath(string $path): string
    {
        // Remove Unicode directional marks and other problematic invisible characters
        $problematicChars = [
            "\u{200E}", // Left-to-Right Mark (LRM)
            "\u{200F}", // Right-to-Left Mark (RLM)
            "\u{202A}", // Left-to-Right Embedding (LRE)
            "\u{202B}", // Right-to-Left Embedding (RLE)
            "\u{202C}", // Pop Directional Formatting (PDF)
            "\u{202D}", // Left-to-Right Override (LRO)
            "\u{202E}", // Right-to-Left Override (RLO)
            "\u{2066}", // Left-to-Right Isolate (LRI)
            "\u{2067}", // Right-to-Left Isolate (RLI)
            "\u{2068}", // First Strong Isolate (FSI)
            "\u{2069}", // Pop Directional Isolate (PDI)
            "\u{FEFF}", // Zero Width No-Break Space (BOM)
            "\u{200B}", // Zero Width Space
            "\u{200C}", // Zero Width Non-Joiner
            "\u{200D}", // Zero Width Joiner
        ];

        // Remove all problematic characters
        $sanitized = str_replace($problematicChars, '', $path);

        // Also remove any other invisible/control characters (U+0000 to U+001F and U+007F to U+009F)
        $sanitized = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', $sanitized);

        // Normalize multiple spaces to single spaces
        $sanitized = preg_replace('/\s+/', ' ', $sanitized);

        // Trim whitespace from the beginning and end
        $sanitized = trim($sanitized);

        return $sanitized;
    }
}
