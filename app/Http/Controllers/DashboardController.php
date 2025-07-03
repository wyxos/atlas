<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        // Count total files
        $totalFiles = File::count();

        // Count audio files
        $audioFiles = File::where('mime_type', 'like', 'audio/%')->count();

        // Count video files
        $videoFiles = File::where('mime_type', 'like', 'video/%')->count();

        // Count image files
        $imageFiles = File::where('mime_type', 'like', 'image/%')->count();

        // Count other files (total - (audio + video + image))
        $otherFiles = $totalFiles - ($audioFiles + $videoFiles + $imageFiles);

        return [
            'totalFiles' => $totalFiles,
            'audioFiles' => $audioFiles,
            'videoFiles' => $videoFiles,
            'imageFiles' => $imageFiles,
            'otherFiles' => $otherFiles,
        ];
    }

    /**
     * Get file statistics as JSON for API requests.
     */
    public function getFileStatsJson(): JsonResponse
    {
        return response()->json($this->getFileStats());
    }
}
