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

    public static function isAccessGated(File $file): bool
    {
        return match (strtolower(trim((string) $file->source))) {
            DeviantArtImages::SOURCE => self::deviantArt($file) !== null,
            default => false,
        };
    }

    /**
     * @return array{provider: string, access_type: ?string, has_access: ?bool, requires_watch: bool, can_unwatch: bool}|null
     */
    private static function deviantArt(File $file): ?array
    {
        $premiumFolderData = self::premiumFolderData($file);
        if (is_array($premiumFolderData)) {
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

        if (! self::hasTierAccessGate($file)) {
            return null;
        }

        $tierAccess = self::nullableString(data_get($file->listing_metadata, 'tier_access'));
        $hasAccess = strtolower((string) $tierAccess) === 'locked' ? false : null;

        return [
            'provider' => 'deviantart',
            'access_type' => 'tier',
            'has_access' => $hasAccess,
            'requires_watch' => false,
            'can_unwatch' => false,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function premiumFolderData(File $file): ?array
    {
        $premiumFolderData = data_get($file->listing_metadata, 'premium_folder_data');

        return is_array($premiumFolderData) ? $premiumFolderData : null;
    }

    private static function hasTierAccessGate(File $file): bool
    {
        $tierAccess = self::nullableString(data_get($file->listing_metadata, 'tier_access'));
        if (strtolower((string) $tierAccess) === 'locked') {
            return true;
        }

        return is_array(data_get($file->listing_metadata, 'primary_tier'));
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
