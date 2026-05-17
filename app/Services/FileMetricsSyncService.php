<?php

namespace App\Services;

use App\Models\File;
use App\Models\Reaction;

class FileMetricsSyncService
{
    public function sync(MetricsService $metrics): void
    {
        $hasPathSql = "path IS NOT NULL AND path != ''";
        $noPathSql = "(path IS NULL OR path = '')";
        $activeSql = 'blacklisted_at IS NULL';
        $activePathSql = "{$activeSql} AND {$hasPathSql}";
        $imageSql = "LOWER(COALESCE(mime_type, '')) LIKE 'image/%'";
        $videoSql = "LOWER(COALESCE(mime_type, '')) LIKE 'video/%'";
        $audioSql = "LOWER(COALESCE(mime_type, '')) LIKE 'audio/%'";
        $otherSql = "LOWER(COALESCE(mime_type, '')) NOT LIKE 'image/%' AND LOWER(COALESCE(mime_type, '')) NOT LIKE 'video/%' AND LOWER(COALESCE(mime_type, '')) NOT LIKE 'audio/%'";

        $fileCounts = File::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN not_found = 1 THEN 1 ELSE 0 END) as not_found_total')
            ->selectRaw('SUM(CASE WHEN downloaded = 1 THEN 1 ELSE 0 END) as downloaded_total')
            ->selectRaw("SUM(CASE WHEN {$hasPathSql} THEN 1 ELSE 0 END) as with_path_total")
            ->selectRaw("SUM(CASE WHEN {$activePathSql} THEN 1 ELSE 0 END) as with_path_not_blacklisted_total")
            ->selectRaw("SUM(CASE WHEN {$activePathSql} AND downloaded = 1 THEN 1 ELSE 0 END) as downloaded_with_path_not_blacklisted_total")
            ->selectRaw("SUM(CASE WHEN source IN ('local', 'Local') THEN 1 ELSE 0 END) as local_total")
            ->selectRaw("SUM(CASE WHEN source IN ('local', 'Local') AND not_found = 0 THEN 1 ELSE 0 END) as local_available_total")
            ->selectRaw("SUM(CASE WHEN source NOT IN ('local', 'Local') AND not_found = 0 THEN 1 ELSE 0 END) as non_local_available_total")
            ->selectRaw("SUM(CASE WHEN {$activeSql} AND {$noPathSql} AND not_found = 1 THEN 1 ELSE 0 END) as not_found_records_only_not_blacklisted_total")
            ->selectRaw("SUM(CASE WHEN {$imageSql} THEN 1 ELSE 0 END) as type_image_total")
            ->selectRaw("SUM(CASE WHEN {$activePathSql} AND {$imageSql} THEN 1 ELSE 0 END) as type_image_with_path_not_blacklisted_total")
            ->selectRaw("SUM(CASE WHEN {$videoSql} THEN 1 ELSE 0 END) as type_video_total")
            ->selectRaw("SUM(CASE WHEN {$activePathSql} AND {$videoSql} THEN 1 ELSE 0 END) as type_video_with_path_not_blacklisted_total")
            ->selectRaw("SUM(CASE WHEN {$audioSql} THEN 1 ELSE 0 END) as type_audio_total")
            ->selectRaw("SUM(CASE WHEN {$activePathSql} AND {$audioSql} THEN 1 ELSE 0 END) as type_audio_with_path_not_blacklisted_total")
            ->selectRaw("SUM(CASE WHEN {$otherSql} THEN 1 ELSE 0 END) as type_other_total")
            ->selectRaw("SUM(CASE WHEN {$activePathSql} AND {$otherSql} THEN 1 ELSE 0 END) as type_other_with_path_not_blacklisted_total")
            ->selectRaw('SUM(CASE WHEN blacklisted_at IS NOT NULL THEN 1 ELSE 0 END) as blacklisted_total')
            ->selectRaw('SUM(CASE WHEN blacklisted_at IS NOT NULL AND auto_blacklisted = 0 THEN 1 ELSE 0 END) as blacklisted_manual_total')
            ->selectRaw('SUM(CASE WHEN blacklisted_at IS NOT NULL AND previewed_count >= '.FilePreviewService::FEED_REMOVED_PREVIEW_COUNT.' THEN 1 ELSE 0 END) as blacklisted_feed_removed_total')
            ->selectRaw('SUM(CASE WHEN blacklisted_at IS NOT NULL AND auto_blacklisted = 0 AND previewed_count < '.FilePreviewService::FEED_REMOVED_PREVIEW_COUNT.' THEN 1 ELSE 0 END) as blacklisted_manual_in_feed_total')
            ->selectRaw('SUM(CASE WHEN blacklisted_at IS NOT NULL AND auto_blacklisted = 1 AND previewed_count < '.FilePreviewService::FEED_REMOVED_PREVIEW_COUNT.' THEN 1 ELSE 0 END) as blacklisted_auto_in_feed_total')
            ->selectRaw('SUM(CASE WHEN auto_blacklisted = 1 THEN 1 ELSE 0 END) as auto_blacklisted_total')
            ->selectRaw('SUM(CASE WHEN blacklisted_at IS NULL AND previewed_count > 0 THEN 1 ELSE 0 END) as previewed_not_blacklisted_total')
            ->first();

        $unreactedCounts = File::query()
            ->whereNull('blacklisted_at')
            ->where('not_found', false)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('reactions')
                    ->whereColumn('reactions.file_id', 'files.id');
            })
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN previewed_count > 0 THEN 1 ELSE 0 END) as previewed_total')
            ->first();
        $unreactedNotBlacklisted = (int) ($unreactedCounts->total ?? 0);
        $unreactedPreviewed = (int) ($unreactedCounts->previewed_total ?? 0);
        $unreactedUnpreviewed = max(0, $unreactedNotBlacklisted - $unreactedPreviewed);

        $reactionCounts = Reaction::query()
            ->select('type')
            ->selectRaw('COUNT(DISTINCT file_id) as total')
            ->whereIn('type', ['love', 'like', 'funny'])
            ->groupBy('type')
            ->pluck('total', 'type');
        $reactedTotal = Reaction::query()
            ->distinct('file_id')
            ->count('file_id');
        $reactedNotBlacklistedTotal = Reaction::query()
            ->join('files', 'files.id', '=', 'reactions.file_id')
            ->whereNull('files.blacklisted_at')
            ->distinct('reactions.file_id')
            ->count('reactions.file_id');

        $metrics->setMetric(MetricsService::KEY_FILES_TOTAL, (int) ($fileCounts->total ?? 0), 'Total files');
        $metrics->setMetric(MetricsService::KEY_FILES_DOWNLOADED, (int) ($fileCounts->downloaded_total ?? 0), 'Downloaded files');
        $metrics->setMetric(MetricsService::KEY_FILES_WITH_PATH, (int) ($fileCounts->with_path_total ?? 0), 'Files with a stored path');
        $metrics->setMetric(MetricsService::KEY_FILES_WITH_PATH_NOT_BLACKLISTED, (int) ($fileCounts->with_path_not_blacklisted_total ?? 0), 'Non-blacklisted files with a stored path');
        $metrics->setMetric(MetricsService::KEY_FILES_DOWNLOADED_WITH_PATH_NOT_BLACKLISTED, (int) ($fileCounts->downloaded_with_path_not_blacklisted_total ?? 0), 'Downloaded non-blacklisted files with a stored path');
        $metrics->setMetric(MetricsService::KEY_FILES_LOCAL, (int) ($fileCounts->local_total ?? 0), 'Local files');
        $metrics->setMetric(MetricsService::KEY_FILES_LOCAL_AVAILABLE, (int) ($fileCounts->local_available_total ?? 0), 'Available local files');
        $metrics->setMetric(MetricsService::KEY_FILES_NON_LOCAL_AVAILABLE, (int) ($fileCounts->non_local_available_total ?? 0), 'Available non-local files');
        $metrics->setMetric(MetricsService::KEY_FILES_NOT_FOUND, (int) ($fileCounts->not_found_total ?? 0), 'Not found files');
        $metrics->setMetric(MetricsService::KEY_FILES_NOT_FOUND_RECORDS_ONLY_NOT_BLACKLISTED, (int) ($fileCounts->not_found_records_only_not_blacklisted_total ?? 0), 'Non-blacklisted catalog-only records marked not found');
        $metrics->setMetric(MetricsService::KEY_FILES_TYPE_IMAGE, (int) ($fileCounts->type_image_total ?? 0), 'Image files');
        $metrics->setMetric(MetricsService::KEY_FILES_TYPE_IMAGE_WITH_PATH_NOT_BLACKLISTED, (int) ($fileCounts->type_image_with_path_not_blacklisted_total ?? 0), 'Non-blacklisted on-disk image files');
        $metrics->setMetric(MetricsService::KEY_FILES_TYPE_VIDEO, (int) ($fileCounts->type_video_total ?? 0), 'Video files');
        $metrics->setMetric(MetricsService::KEY_FILES_TYPE_VIDEO_WITH_PATH_NOT_BLACKLISTED, (int) ($fileCounts->type_video_with_path_not_blacklisted_total ?? 0), 'Non-blacklisted on-disk video files');
        $metrics->setMetric(MetricsService::KEY_FILES_TYPE_AUDIO, (int) ($fileCounts->type_audio_total ?? 0), 'Audio files');
        $metrics->setMetric(MetricsService::KEY_FILES_TYPE_AUDIO_WITH_PATH_NOT_BLACKLISTED, (int) ($fileCounts->type_audio_with_path_not_blacklisted_total ?? 0), 'Non-blacklisted on-disk audio files');
        $metrics->setMetric(MetricsService::KEY_FILES_TYPE_OTHER, (int) ($fileCounts->type_other_total ?? 0), 'Other file types');
        $metrics->setMetric(MetricsService::KEY_FILES_TYPE_OTHER_WITH_PATH_NOT_BLACKLISTED, (int) ($fileCounts->type_other_with_path_not_blacklisted_total ?? 0), 'Non-blacklisted on-disk other files');
        $metrics->setMetric(MetricsService::KEY_FILES_REACTED, $reactedTotal, 'Files with any reaction');
        $metrics->setMetric(MetricsService::KEY_FILES_REACTED_NOT_BLACKLISTED, $reactedNotBlacklistedTotal, 'Non-blacklisted files with any reaction');
        $metrics->setMetric(MetricsService::KEY_FILES_PREVIEWED_NOT_BLACKLISTED, (int) ($fileCounts->previewed_not_blacklisted_total ?? 0), 'Previewed, not blacklisted files');
        $metrics->setMetric(MetricsService::KEY_FILES_BLACKLISTED_TOTAL, (int) ($fileCounts->blacklisted_total ?? 0), 'Blacklisted files');
        $metrics->setMetric(MetricsService::KEY_FILES_BLACKLISTED_MANUAL, (int) ($fileCounts->blacklisted_manual_total ?? 0), 'Manual blacklisted files');
        $metrics->setMetric(MetricsService::KEY_FILES_BLACKLISTED_FEED_REMOVED, (int) ($fileCounts->blacklisted_feed_removed_total ?? 0), 'Feed-removed blacklisted files');
        $metrics->setMetric(MetricsService::KEY_FILES_BLACKLISTED_MANUAL_IN_FEED, (int) ($fileCounts->blacklisted_manual_in_feed_total ?? 0), 'Manual blacklisted files still in feed');
        $metrics->setMetric(MetricsService::KEY_FILES_BLACKLISTED_AUTO_IN_FEED, (int) ($fileCounts->blacklisted_auto_in_feed_total ?? 0), 'Auto blacklisted files still in feed');
        $metrics->setMetric(MetricsService::KEY_FILES_AUTO_BLACKLISTED, (int) ($fileCounts->auto_blacklisted_total ?? 0), 'Auto blacklisted files');
        $metrics->setMetric(MetricsService::KEY_FILES_UNREACTED_NOT_BLACKLISTED, $unreactedNotBlacklisted, 'Unreacted, not blacklisted or missing');
        $metrics->setMetric(MetricsService::KEY_FILES_UNREACTED_PREVIEWED_NOT_BLACKLISTED, $unreactedPreviewed, 'Unreacted previewed, not blacklisted or missing');
        $metrics->setMetric(MetricsService::KEY_FILES_UNREACTED_UNPREVIEWED_NOT_BLACKLISTED, $unreactedUnpreviewed, 'Unreacted not previewed, not blacklisted or missing');
        $metrics->setMetric(MetricsService::KEY_REACTIONS_LOVE, (int) ($reactionCounts['love'] ?? 0), 'Files with love reactions');
        $metrics->setMetric(MetricsService::KEY_REACTIONS_LIKE, (int) ($reactionCounts['like'] ?? 0), 'Files with like reactions');
        $metrics->setMetric(MetricsService::KEY_REACTIONS_FUNNY, (int) ($reactionCounts['funny'] ?? 0), 'Files with funny reactions');
    }
}
