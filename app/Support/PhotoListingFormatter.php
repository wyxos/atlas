<?php

namespace App\Support;

use App\Models\File;

/**
 * @deprecated Use FileListingFormatter instead. This class is kept for backward compatibility.
 */
class PhotoListingFormatter
{
    /**
     * @param  array<string, mixed>  $reactions
     * @param  callable(File, string, array&): string  $remoteUrlDecorator
     * @param  array<string, mixed>  $serviceCache
     * @return array<string, mixed>|null
     */
    public static function format(?File $file, array $reactions, int $page, callable $remoteUrlDecorator, array &$serviceCache): ?array
    {
        return FileListingFormatter::format($file, $reactions, $page, $remoteUrlDecorator, $serviceCache);
    }
}
