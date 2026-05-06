<?php

declare(strict_types=1);

namespace App\Enums;

final class LibraryScanRunStatus
{
    public const PENDING = 'pending';

    public const SCANNING = 'scanning';

    public const PROCESSING = 'processing';

    public const PAUSED = 'paused';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELED = 'canceled';

    /**
     * @return list<string>
     */
    public static function active(): array
    {
        return [
            self::PENDING,
            self::SCANNING,
            self::PROCESSING,
            self::PAUSED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function terminal(): array
    {
        return [
            self::COMPLETED,
            self::FAILED,
            self::CANCELED,
        ];
    }
}
