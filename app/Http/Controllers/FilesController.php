<?php

namespace App\Http\Controllers;

use App\Listings\FileListing;
use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class FilesController extends Controller
{
    /**
     * Display a listing of the files.
     */
    public function index(FileListing $listing): JsonResponse
    {
        Gate::authorize('viewAny', File::class);

        return response()->json($listing->handle());
    }

    /**
     * Display the specified file.
     */
    public function show(File $file): JsonResponse
    {
        Gate::authorize('view', $file);

        return response()->json([
            'file' => new \App\Http\Resources\FileResource($file),
        ]);
    }

    /**
     * Remove the specified file from storage.
     */
    public function destroy(File $file): JsonResponse
    {
        Gate::authorize('delete', $file);

        $file->delete();

        return response()->json([
            'message' => 'File deleted successfully.',
        ]);
    }
}
