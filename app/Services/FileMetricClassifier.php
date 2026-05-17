<?php

namespace App\Services;

use App\Models\File;

class FileMetricClassifier
{
    public function countsAsUnreactedBacklog(bool $isBlacklisted, bool $isNotFound, int $reactionCount): bool
    {
        return ! $isBlacklisted && ! $isNotFound && $reactionCount === 0;
    }

    public function unreactedPreviewMetricKey(File $file): string
    {
        return (int) $file->previewed_count > 0
            ? MetricsService::KEY_FILES_UNREACTED_PREVIEWED_NOT_BLACKLISTED
            : MetricsService::KEY_FILES_UNREACTED_UNPREVIEWED_NOT_BLACKLISTED;
    }

    public function willBeFeedRemoved(File $file, ?int $minimumPreviewedCount): bool
    {
        return (int) $file->previewed_count >= FilePreviewService::FEED_REMOVED_PREVIEW_COUNT
            || (is_int($minimumPreviewedCount) && $minimumPreviewedCount >= FilePreviewService::FEED_REMOVED_PREVIEW_COUNT);
    }

    public function isFeedRemoved(File $file): bool
    {
        return (int) $file->previewed_count >= FilePreviewService::FEED_REMOVED_PREVIEW_COUNT;
    }

    public function hasPath(File $file): bool
    {
        return is_string($file->path) && $file->path !== '';
    }

    public function storedFileTypeMetricKey(File $file): string
    {
        $mimeType = strtolower((string) $file->mime_type);

        if (str_starts_with($mimeType, 'image/')) {
            return MetricsService::KEY_FILES_TYPE_IMAGE_WITH_PATH_NOT_BLACKLISTED;
        }

        if (str_starts_with($mimeType, 'video/')) {
            return MetricsService::KEY_FILES_TYPE_VIDEO_WITH_PATH_NOT_BLACKLISTED;
        }

        if (str_starts_with($mimeType, 'audio/')) {
            return MetricsService::KEY_FILES_TYPE_AUDIO_WITH_PATH_NOT_BLACKLISTED;
        }

        return MetricsService::KEY_FILES_TYPE_OTHER_WITH_PATH_NOT_BLACKLISTED;
    }

    public function isLocalSource(File $file): bool
    {
        return strtolower(trim((string) $file->source)) === 'local';
    }

    public function isNotFound(File $file): bool
    {
        return (bool) $file->not_found;
    }
}
