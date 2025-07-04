<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            // Use a single query with conditional aggregation for file types and sizes
            $fileTypeStats = File::selectRaw('
                COUNT(*) as total_files,
                COUNT(CASE WHEN mime_type LIKE "audio/%" THEN 1 END) as audio_files,
                COUNT(CASE WHEN mime_type LIKE "video/%" THEN 1 END) as video_files,
                COUNT(CASE WHEN mime_type LIKE "image/%" THEN 1 END) as image_files,
                COUNT(CASE WHEN not_found = 1 THEN 1 END) as not_found_files,
                COALESCE(SUM(CASE WHEN mime_type LIKE "audio/%" THEN size END), 0) as audio_size,
                COALESCE(SUM(CASE WHEN mime_type LIKE "video/%" THEN size END), 0) as video_size,
                COALESCE(SUM(CASE WHEN mime_type LIKE "image/%" THEN size END), 0) as image_size,
                COALESCE(SUM(CASE WHEN mime_type NOT LIKE "audio/%" AND mime_type NOT LIKE "video/%" AND mime_type NOT LIKE "image/%" THEN size END), 0) as other_size
            ')->first();

            // Get metadata statistics with a single query
            $metadataStats = File::selectRaw('
                COUNT(CASE WHEN file_metadata.id IS NULL THEN 1 END) as without_metadata_files,
                COUNT(CASE WHEN file_metadata.is_review_required = 1 THEN 1 END) as requires_review_files
            ')
            ->leftJoin('file_metadata', 'files.id', '=', 'file_metadata.file_id')
            ->first();

            // Calculate other files count
            $otherFiles = $fileTypeStats->total_files - (
                $fileTypeStats->audio_files + 
                $fileTypeStats->video_files + 
                $fileTypeStats->image_files
            );

            return [
                'totalFiles' => (int) $fileTypeStats->total_files,
                'audioFiles' => (int) $fileTypeStats->audio_files,
                'videoFiles' => (int) $fileTypeStats->video_files,
                'imageFiles' => (int) $fileTypeStats->image_files,
                'otherFiles' => (int) $otherFiles,
                'notFoundFiles' => (int) $fileTypeStats->not_found_files,
                'withoutMetadataFiles' => (int) $metadataStats->without_metadata_files,
                'requiresReviewFiles' => (int) $metadataStats->requires_review_files,
                'audioSize' => (int) $fileTypeStats->audio_size,
                'videoSize' => (int) $fileTypeStats->video_size,
                'imageSize' => (int) $fileTypeStats->image_size,
                'otherSize' => (int) $fileTypeStats->other_size,
            ];
        });
    }

    /**
     * Get file statistics as JSON for API requests.
     */
    public function getFileStatsJson(): JsonResponse
    {
        return response()->json($this->getFileStats());
    }
}
