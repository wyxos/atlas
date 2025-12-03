<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Reaction;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the dashboard.
     */
    public function index(): Response
    {
        // Do not compute heavy stats during initial render; the client fetches them asynchronously.
        return Inertia::render('Dashboard', [
            'fileStats' => [],
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
     * Uses Scout/Typesense facets for fast counts on large datasets.
     */
    private function calculateFileStats(): array
    {
        // Count everything directly in the database; composite indexes keep this fast.
        $baseQuery = File::query()->whereNull('blacklisted_at');

        $totalFiles = (int) (clone $baseQuery)->count();
        $audioFilesCount = (int) (clone $baseQuery)->audio()->count();
        $videoFilesCount = (int) (clone $baseQuery)->video()->count();
        $imageFilesCount = (int) (clone $baseQuery)->image()->count();
        $otherFiles = max(0, $totalFiles - ($audioFilesCount + $videoFilesCount + $imageFilesCount));

        $totalNotFound = (int) (clone $baseQuery)->where('not_found', true)->count();
        $audioNotFound = (int) (clone $baseQuery)->audio()->where('not_found', true)->count();
        $videoNotFound = (int) (clone $baseQuery)->video()->where('not_found', true)->count();
        $imageNotFound = (int) (clone $baseQuery)->image()->where('not_found', true)->count();

        // Size sums still use database (Typesense doesn't support SUM aggregations)
        // These are fast with the composite index on ['mime_type', 'size']
        $sizeTotals = File::selectRaw("
            COALESCE(SUM(CASE WHEN mime_type LIKE 'audio/%' THEN size END), 0) as audio_size,
            COALESCE(SUM(CASE WHEN mime_type LIKE 'video/%' THEN size END), 0) as video_size,
            COALESCE(SUM(CASE WHEN mime_type LIKE 'image/%' THEN size END), 0) as image_size,
            COALESCE(SUM(CASE WHEN mime_type NOT LIKE 'audio/%' AND mime_type NOT LIKE 'video/%' AND mime_type NOT LIKE 'image/%' THEN size END), 0) as other_size
        ")->whereNull('blacklisted_at')->first();

        // Lightweight global reaction counts (no joins)
        $globalLoved = Reaction::where('type', 'love')->count();
        $globalLiked = Reaction::where('type', 'like')->count();
        $globalDisliked = Reaction::where('type', 'dislike')->count();
        $globalLaughedAt = Reaction::where('type', 'funny')->count();
        // "No rating" approximated as files without any reaction (anti-join via distinct reaction file ids)
        $reactedDistinctFiles = DB::table('reactions')->distinct()->count('file_id');
        $globalNoRating = max(0, $totalFiles - (int) $reactedDistinctFiles);

        // Disk space information (fast)
        $diskSpaceInfo = $this->getDiskSpaceInfo();

        return [
            // Audio Count & Space Usage
            'audioFilesCount' => $audioFilesCount,
            'audioSpaceUsed' => (int) ($sizeTotals->audio_size ?? 0),
            'audioNotFound' => $audioNotFound,

            // Video Count & Space Usage
            'videoFilesCount' => $videoFilesCount,
            'videoSpaceUsed' => (int) ($sizeTotals->video_size ?? 0),
            'videoNotFound' => $videoNotFound,

            // Image Count & Space Usage
            'imageFilesCount' => $imageFilesCount,
            'imageSpaceUsed' => (int) ($sizeTotals->image_size ?? 0),
            'imageNotFound' => $imageNotFound,

            // Total Files Not Found
            'totalFilesNotFound' => $totalNotFound,

            // File Type Distribution (for charts)
            'audioFiles' => $audioFilesCount,
            'videoFiles' => $videoFilesCount,
            'imageFiles' => $imageFilesCount,
            'otherFiles' => (int) $otherFiles,
            'audioSize' => (int) ($sizeTotals->audio_size ?? 0),
            'videoSize' => (int) ($sizeTotals->video_size ?? 0),
            'imageSize' => (int) ($sizeTotals->image_size ?? 0),
            'otherSize' => (int) ($sizeTotals->other_size ?? 0),

            // Global Reaction Stats (lightweight)
            'globalLoved' => (int) $globalLoved,
            'globalLiked' => (int) $globalLiked,
            'globalDisliked' => (int) $globalDisliked,
            'globalLaughedAt' => (int) $globalLaughedAt,
            'globalNoRating' => (int) $globalNoRating,

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
