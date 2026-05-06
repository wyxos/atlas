<?php

declare(strict_types=1);

namespace App\Enums;

final class LibraryScanItemStatus
{
    public const PENDING = 'pending';

    public const IMPORTING = 'importing';

    public const IMPORTED = 'imported';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELED = 'canceled';

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
