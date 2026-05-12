<?php

declare(strict_types=1);

namespace App\Enums;

final class MediaProcessorTaskStatus
{
    public const string SUBMITTING = 'submitting';

    public const string QUEUED = 'queued';

    public const string ACCEPTED = 'accepted';

    public const string PROCESSING = 'processing';

    public const string COMPLETED = 'completed';

    public const string FAILED = 'failed';

    /**
     * @return list<string>
     */
    public static function active(): array
    {
        return [
            self::SUBMITTING,
            self::QUEUED,
            self::ACCEPTED,
            self::PROCESSING,
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
        ];
    }
}
