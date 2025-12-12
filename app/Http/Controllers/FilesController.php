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
     * Serve the file content.
     */
    public function serve(File $file)
    {
        Gate::authorize('view', $file);

        if (! $file->path) {
            abort(404, 'File not found');
        }

        $fullPath = storage_path('app/'.$file->path);

        if (! file_exists($fullPath)) {
            abort(404, 'File not found');
        }

        return response()->file($fullPath, [
            'Content-Type' => $file->mime_type ?? 'application/octet-stream',
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

    /**
     * Increment the preview count for a file.
     */
    public function incrementPreview(File $file): JsonResponse
    {
        Gate::authorize('view', $file);

        $file->increment('previewed_count');
        $file->touch('previewed_at');

        return response()->json([
            'message' => 'Preview count incremented.',
            'previewed_count' => $file->previewed_count,
        ]);
    }
}
