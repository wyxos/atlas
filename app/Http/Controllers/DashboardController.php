<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the dashboard.
     */
    public function index(): Response
    {
        return Inertia::render('Dashboard', [
            'fileStats' => $this->getFileStats(),
        ]);
    }

    /**
     * Get file statistics for the dashboard.
     */
    public function getFileStats(): array
    {
        return Cache::remember('dashboard.file_stats', 300, function () {
            return $this->calculateFileStats();
        });
    }

    /**
     * Get file statistics as JSON for API requests.
     */
    public function getFileStatsJson(): JsonResponse
    {
        return response()->json($this->getFileStats());
    }

    /**
     * Clear the dashboard statistics cache.
     */
    public function clearCache()
    {
        Cache::forget('dashboard.file_stats');

        return redirect()->route('dashboard')->with('success', 'Dashboard cache cleared successfully');
    }

    /**
     * Calculate file statistics without caching.
     */
    private function calculateFileStats(): array
    {
        // Audio-specific statistics with a single optimized query
        $audioStats = File::selectRaw('
            COUNT(*) as audio_files_count,
            COUNT(CASE WHEN not_found = true THEN 1 END) as audio_not_found,
            COALESCE(SUM(size), 0) as audio_size,
            COUNT(CASE WHEN liked = true THEN 1 END) as audio_liked,
            COUNT(CASE WHEN loved = true THEN 1 END) as audio_loved,
            COUNT(CASE WHEN disliked = true THEN 1 END) as audio_disliked,
            COUNT(CASE WHEN funny = true THEN 1 END) as audio_laughed_at,
            COUNT(CASE WHEN liked = false AND loved = false AND disliked = false AND funny = false THEN 1 END) as audio_no_rating
        ')
            ->where('mime_type', 'like', 'audio/%')
            ->first();

        // Audio metadata statistics with a single query
        $audioMetadataStats = File::selectRaw('
            COUNT(*) as total_audio,
            COUNT(CASE WHEN file_metadata.id IS NOT NULL THEN 1 END) as audio_with_metadata,
            COUNT(CASE WHEN file_metadata.id IS NULL THEN 1 END) as audio_without_metadata,
            COUNT(CASE WHEN file_metadata.is_review_required = true THEN 1 END) as audio_metadata_review_required,
            COUNT(CASE WHEN file_metadata.is_review_required = false AND file_metadata.id IS NOT NULL THEN 1 END) as audio_metadata_review_not_required
        ')
            ->where('mime_type', 'like', 'audio/%')
            ->leftJoin('file_metadata', 'files.id', '=', 'file_metadata.file_id')
            ->first();

        // Global ratings statistics (all files)
        $globalRatings = File::selectRaw('
            COUNT(CASE WHEN liked = true THEN 1 END) as global_liked,
            COUNT(CASE WHEN loved = true THEN 1 END) as global_loved,
            COUNT(CASE WHEN disliked = true THEN 1 END) as global_disliked,
            COUNT(CASE WHEN funny = true THEN 1 END) as global_laughed_at,
            COUNT(CASE WHEN liked = false AND loved = false AND disliked = false AND funny = false THEN 1 END) as global_no_rating
        ')
            ->first();

        // Video statistics with count, size, and not found
        $videoStats = File::selectRaw('
            COUNT(*) as video_files_count,
            COUNT(CASE WHEN not_found = true THEN 1 END) as video_not_found,
            COALESCE(SUM(size), 0) as video_size,
            COUNT(CASE WHEN liked = true THEN 1 END) as video_liked,
            COUNT(CASE WHEN loved = true THEN 1 END) as video_loved,
            COUNT(CASE WHEN disliked = true THEN 1 END) as video_disliked,
            COUNT(CASE WHEN funny = true THEN 1 END) as video_laughed_at,
            COUNT(CASE WHEN liked = false AND loved = false AND disliked = false AND funny = false THEN 1 END) as video_no_rating
        ')
            ->where('mime_type', 'like', 'video/%')
            ->first();

        // Image statistics with count, size, and not found
        $imageStats = File::selectRaw('
            COUNT(*) as image_files_count,
            COUNT(CASE WHEN not_found = true THEN 1 END) as image_not_found,
            COALESCE(SUM(size), 0) as image_size,
            COUNT(CASE WHEN liked = true THEN 1 END) as image_liked,
            COUNT(CASE WHEN loved = true THEN 1 END) as image_loved,
            COUNT(CASE WHEN disliked = true THEN 1 END) as image_disliked,
            COUNT(CASE WHEN funny = true THEN 1 END) as image_laughed_at,
            COUNT(CASE WHEN liked = false AND loved = false AND disliked = false AND funny = false THEN 1 END) as image_no_rating
        ')
            ->where('mime_type', 'like', 'image/%')
            ->first();

        // Total files not found across all types
        $totalNotFound = File::where('not_found', true)->count();

        // Global metadata statistics (all files)
        $globalMetadataStats = File::selectRaw('
            COUNT(*) as total_files,
            COUNT(CASE WHEN file_metadata.id IS NOT NULL THEN 1 END) as global_with_metadata,
            COUNT(CASE WHEN file_metadata.id IS NULL THEN 1 END) as global_without_metadata,
            COUNT(CASE WHEN file_metadata.is_review_required = true THEN 1 END) as global_metadata_review_required,
            COUNT(CASE WHEN file_metadata.is_review_required = false AND file_metadata.id IS NOT NULL THEN 1 END) as global_metadata_review_not_required
        ')
            ->leftJoin('file_metadata', 'files.id', '=', 'file_metadata.file_id')
            ->first();

        // Get overall file type distribution for the pie chart
        $fileTypeStats = File::selectRaw("
            COUNT(CASE WHEN mime_type LIKE 'audio/%' THEN 1 END) as audio_files,
            COUNT(CASE WHEN mime_type LIKE 'video/%' THEN 1 END) as video_files,
            COUNT(CASE WHEN mime_type LIKE 'image/%' THEN 1 END) as image_files,
            COALESCE(SUM(CASE WHEN mime_type LIKE 'audio/%' THEN size END), 0) as audio_size,
            COALESCE(SUM(CASE WHEN mime_type LIKE 'video/%' THEN size END), 0) as video_size,
            COALESCE(SUM(CASE WHEN mime_type LIKE 'image/%' THEN size END), 0) as image_size,
            COALESCE(SUM(CASE WHEN mime_type NOT LIKE 'audio/%' AND mime_type NOT LIKE 'video/%' AND mime_type NOT LIKE 'image/%' THEN size END), 0) as other_size
        ")->first();

        $totalFiles = $fileTypeStats->audio_files + $fileTypeStats->video_files + $fileTypeStats->image_files;
        $otherFiles = File::count() - $totalFiles;

        // Get disk space information
        $diskSpaceInfo = $this->getDiskSpaceInfo();

        return [
            // Audio Count & Space Usage
            'audioFilesCount' => (int) $audioStats->audio_files_count,
            'audioSpaceUsed' => (int) $audioStats->audio_size,
            'audioNotFound' => (int) $audioStats->audio_not_found,

            // Video Count & Space Usage
            'videoFilesCount' => (int) $videoStats->video_files_count,
            'videoSpaceUsed' => (int) $videoStats->video_size,
            'videoNotFound' => (int) $videoStats->video_not_found,

            // Image Count & Space Usage
            'imageFilesCount' => (int) $imageStats->image_files_count,
            'imageSpaceUsed' => (int) $imageStats->image_size,
            'imageNotFound' => (int) $imageStats->image_not_found,

            // Total Files Not Found
            'totalFilesNotFound' => (int) $totalNotFound,

            // Audio Metadata Stats
            'audioWithMetadata' => (int) $audioMetadataStats->audio_with_metadata,
            'audioWithoutMetadata' => (int) $audioMetadataStats->audio_without_metadata,
            'audioMetadataReviewRequired' => (int) $audioMetadataStats->audio_metadata_review_required,
            'audioMetadataReviewNotRequired' => (int) $audioMetadataStats->audio_metadata_review_not_required,

            // Global Metadata Stats
            'globalWithMetadata' => (int) $globalMetadataStats->global_with_metadata,
            'globalWithoutMetadata' => (int) $globalMetadataStats->global_without_metadata,
            'globalMetadataReviewRequired' => (int) $globalMetadataStats->global_metadata_review_required,
            'globalMetadataReviewNotRequired' => (int) $globalMetadataStats->global_metadata_review_not_required,

            // Audio Rating Stats
            'audioLoved' => (int) $audioStats->audio_loved,
            'audioLiked' => (int) $audioStats->audio_liked,
            'audioDisliked' => (int) $audioStats->audio_disliked,
            'audioLaughedAt' => (int) $audioStats->audio_laughed_at,
            'audioNoRating' => (int) $audioStats->audio_no_rating,

            // Global Rating Stats
            'globalLoved' => (int) $globalRatings->global_loved,
            'globalLiked' => (int) $globalRatings->global_liked,
            'globalDisliked' => (int) $globalRatings->global_disliked,
            'globalLaughedAt' => (int) $globalRatings->global_laughed_at,
            'globalNoRating' => (int) $globalRatings->global_no_rating,

            // Video Rating Stats
            'videoLoved' => (int) $videoStats->video_loved,
            'videoLiked' => (int) $videoStats->video_liked,
            'videoDisliked' => (int) $videoStats->video_disliked,
            'videoLaughedAt' => (int) $videoStats->video_laughed_at,
            'videoNoRating' => (int) $videoStats->video_no_rating,

            // Image Rating Stats
            'imageLoved' => (int) $imageStats->image_loved,
            'imageLiked' => (int) $imageStats->image_liked,
            'imageDisliked' => (int) $imageStats->image_disliked,
            'imageLaughedAt' => (int) $imageStats->image_laughed_at,
            'imageNoRating' => (int) $imageStats->image_no_rating,

            // File Type Distribution (for pie chart)
            'audioFiles' => (int) $fileTypeStats->audio_files,
            'videoFiles' => (int) $fileTypeStats->video_files,
            'imageFiles' => (int) $fileTypeStats->image_files,
            'otherFiles' => (int) $otherFiles,
            'audioSize' => (int) $fileTypeStats->audio_size,
            'videoSize' => (int) $fileTypeStats->video_size,
            'imageSize' => (int) $fileTypeStats->image_size,
            'otherSize' => (int) $fileTypeStats->other_size,

            // Disk Space Information
            'diskSpaceTotal' => $diskSpaceInfo['total'],
            'diskSpaceUsed' => $diskSpaceInfo['used'],
            'diskSpaceFree' => $diskSpaceInfo['free'],
            'diskSpaceUsedPercent' => $diskSpaceInfo['used_percent'],
        ];
    }

    /**
     * Get disk space information for the application directory.
     */
    private function getDiskSpaceInfo(): array
    {
        try {
            // Get the application's storage path
            $path = storage_path();

            // Get disk space information
            $totalSpace = disk_total_space($path);
            $freeSpace = disk_free_space($path);

            if ($totalSpace === false || $freeSpace === false) {
                // Fallback values if disk_*_space functions fail
                return [
                    'total' => 0,
                    'used' => 0,
                    'free' => 0,
                    'used_percent' => 0,
                ];
            }

            $usedSpace = $totalSpace - $freeSpace;
            $usedPercent = $totalSpace > 0 ? round(($usedSpace / $totalSpace) * 100, 1) : 0;

            return [
                'total' => (int) $totalSpace,
                'used' => (int) $usedSpace,
                'free' => (int) $freeSpace,
                'used_percent' => (float) $usedPercent,
            ];
        } catch (Exception $e) {
            // Return fallback values
            return [
                'total' => 0,
                'used' => 0,
                'free' => 0,
                'used_percent' => 0,
            ];
        }
    }
}
