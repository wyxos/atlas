<?php

namespace App\Support;

class PartitionedPathHelper
{
    /**
     * Generate a partitioned path for a file in the downloads directory.
     *
     * @param  string  $filename  The filename (with or without extension)
     * @param  int  $subdirLength  Number of characters to use for subdirectory name (default: 2)
     * @return string The partitioned path (e.g., "downloads/ab/filename.ext")
     */
    public static function generatePath(string $filename, int $subdirLength = 2): string
    {
        $subdir = self::getSubdirectory($filename, $subdirLength);

        return "downloads/{$subdir}/{$filename}";
    }

    /**
     * Get the subdirectory name from a filename.
     *
     * @param  string  $filename  The filename
     * @param  int  $length  Number of characters to use for subdirectory name
     * @return string The subdirectory name (lowercase)
     */
    public static function getSubdirectory(string $filename, int $length = 2): string
    {
        // Remove extension for hashing
        $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);

        // If filename is already random (40 chars alphanumeric), use first N chars
        if (preg_match('/^[A-Za-z0-9]{40}$/', $nameWithoutExt) === 1) {
            return strtolower(substr($nameWithoutExt, 0, $length));
        }

        // Otherwise, use hash of filename to ensure even distribution
        $hash = hash('sha256', $nameWithoutExt);

        return strtolower(substr($hash, 0, $length));
    }
}

