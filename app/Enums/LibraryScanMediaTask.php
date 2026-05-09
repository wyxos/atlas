<?php

declare(strict_types=1);

namespace App\Enums;

final class LibraryScanMediaTask
{
    public const string PREVIEW_QUEUE = 'media-previews';

    public const string CONVERSION_QUEUE = 'media-conversions';

    public const string TASK_PREVIEW_ASSETS = 'preview_assets';

    public const string TASK_AUDIO_NORMALIZATION = 'audio_normalization';

    public const string TASK_VIDEO_STREAMABLE = 'video_streamable';

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_PROCESSING = 'processing';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_CANCELED = 'canceled';

    public const string PHASE_QUEUED = 'queued';

    public const string PHASE_MEDIA_QUEUED = 'media_queued';

    public const string PHASE_MEDIA_PROCESSING = 'media_processing';

    public const string PHASE_COMPLETED = 'completed';

    public const string PHASE_FAILED = 'failed';

    public const string PHASE_CANCELED = 'canceled';

    /**
     * @return list<string>
     */
    public static function terminal(): array
    {
        return [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELED,
        ];
    }
}
