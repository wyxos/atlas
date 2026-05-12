<?php

declare(strict_types=1);

namespace App\Enums;

final class MediaProcessorOperation
{
    public const string IMAGE_PREVIEW = 'image_preview';

    public const string VIDEO_PREVIEW = 'video_preview';

    public const string AUDIO_NORMALIZATION = 'audio_normalization';

    public const string STREAMABLE_VIDEO = 'streamable_video';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::IMAGE_PREVIEW,
            self::VIDEO_PREVIEW,
            self::AUDIO_NORMALIZATION,
            self::STREAMABLE_VIDEO,
        ];
    }
}
