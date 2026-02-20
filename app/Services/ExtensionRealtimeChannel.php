<?php

namespace App\Services;

class ExtensionRealtimeChannel
{
    private static ?int $cachedUserId = null;

    public static function userId(): ?int
    {
        if (self::$cachedUserId !== null) {
            return self::$cachedUserId > 0 ? self::$cachedUserId : null;
        }

        $configured = (int) config('downloads.extension_user_id', 0);
        if ($configured > 0) {
            self::$cachedUserId = $configured;

            return $configured;
        }

        try {
            $resolved = app(ExtensionUserResolver::class)->resolve();
            self::$cachedUserId = (int) $resolved->id;

            return self::$cachedUserId > 0 ? self::$cachedUserId : null;
        } catch (\Throwable) {
            self::$cachedUserId = 0;

            return null;
        }
    }

    public static function channelName(?int $userId = null): ?string
    {
        $resolvedUserId = $userId ?? self::userId();
        if (! $resolvedUserId || $resolvedUserId <= 0) {
            return null;
        }

        return "extension-downloads.{$resolvedUserId}";
    }

    public static function privateChannelName(?int $userId = null): ?string
    {
        $channelName = self::channelName($userId);
        if (! $channelName) {
            return null;
        }

        return "private-{$channelName}";
    }
}
