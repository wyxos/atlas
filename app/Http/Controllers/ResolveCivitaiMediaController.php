<?php

namespace App\Http\Controllers;

use App\Jobs\ResolveCivitaiMedia;
use App\Models\File;
use Illuminate\Http\JsonResponse;

class ResolveCivitaiMediaController extends Controller
{
    public function __invoke(File $file): JsonResponse
    {
        if (strcasecmp((string) $file->source, 'CivitAI') !== 0) {
            return response()->json([
                'resolved' => false,
                'not_found' => false,
                'message' => 'Unsupported file source.',
            ], 422);
        }

        // Dispatch job to handle resolution in background
        ResolveCivitaiMedia::dispatch($file);

        // Return immediately with job dispatched status
        return response()->json([
            'id' => $file->id,
            'dispatched' => true,
            'message' => 'Resolution job dispatched. Results will be broadcast when complete.',
        ]);
    }
}
