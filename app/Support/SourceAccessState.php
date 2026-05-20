<?php

namespace App\Support;

use App\Models\File;
use App\Services\DeviantArtImages;

final class SourceAccessState
{
    /**
     * @return array{provider: string, access_type: ?string, has_access: ?bool, requires_watch: bool, can_unwatch: bool}|null
     */
    public static function forFile(File $file): ?array
    {
        return match (strtolower(trim((string) $file->source))) {
            DeviantArtImages::SOURCE => self::deviantArt($file),
            default => null,
        };
    }

    /**
     * @return array{provider: string, access_type: ?string, has_access: ?bool, requires_watch: bool, can_unwatch: bool}|null
     */
    private static function deviantArt(File $file): ?array
    {
        $premiumFolderData = data_get($file->listing_metadata, 'premium_folder_data');
        if (! is_array($premiumFolderData)) {
            return null;
        }

        $accessType = self::nullableString($premiumFolderData['type'] ?? null);
        $normalizedAccessType = strtolower((string) $accessType);
        $hasAccess = self::nullableBoolean($premiumFolderData['has_access'] ?? null);
        $isWatcherGate = $normalizedAccessType === 'watchers';

        return [
            'provider' => 'deviantart',
            'access_type' => $accessType,
            'has_access' => $hasAccess,
            'requires_watch' => $isWatcherGate && $hasAccess === false,
            'can_unwatch' => $isWatcherGate && $hasAccess === true,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private static function nullableBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return null;
    }
}
