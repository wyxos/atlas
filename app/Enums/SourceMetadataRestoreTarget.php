<?php

declare(strict_types=1);

namespace App\Enums;

use InvalidArgumentException;

final class SourceMetadataRestoreTarget
{
    public const string LISTING = 'listing';

    public const string DETAIL = 'detail';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::LISTING,
            self::DETAIL,
        ];
    }

    public static function isValid(string $target): bool
    {
        return in_array($target, self::values(), true);
    }

    public static function column(string $target): string
    {
        return match ($target) {
            self::LISTING => 'listing_metadata',
            self::DETAIL => 'detail_metadata',
            default => throw new InvalidArgumentException("Unsupported metadata restore target [{$target}]."),
        };
    }

    public static function label(string $target): string
    {
        return match ($target) {
            self::LISTING => 'listing metadata',
            self::DETAIL => 'detail metadata',
            default => throw new InvalidArgumentException("Unsupported metadata restore target [{$target}]."),
        };
    }
}
