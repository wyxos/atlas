<?php

declare(strict_types=1);

namespace App\Enums;

final class ModerationFeedRemovalRunStatus
{
    public const PENDING = 'pending';

    public const PREVIEWING = 'previewing';

    public const PREVIEWED = 'previewed';

    public const APPLYING = 'applying';

    public const APPLIED = 'applied';

    public const STALE = 'stale';

    public const FAILED = 'failed';

    /**
     * @return list<string>
     */
    public static function active(): array
    {
        return [
            self::PENDING,
            self::PREVIEWING,
            self::APPLYING,
        ];
    }
}
