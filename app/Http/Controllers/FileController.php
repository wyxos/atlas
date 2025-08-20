<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadFile;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class FileController extends Controller
{
    /**
     * Display a listing of the files.
     */
    public function index(Request $request): Response
    {
        // Check if the user is an admin
        if (!Auth::user()->is_admin) {
            abort(HttpResponse::HTTP_FORBIDDEN, 'Access denied. Only admins can access this page.');
        }

        $query = File::query();
        $searchResults = [];

        // Handle search using Laravel Scout (similar to audio route)
        $searchQuery = $request->input('query', '');
        $searchResults = [];

        if ($searchQuery) {
            // Try Laravel Scout search first (like in the audio route)
            try {
                $searchResults = File::search($searchQuery)
                    ->query(function ($builder) use ($request) {
                        // Apply not_found filter to search results if specified
                        $notFoundFilter = $request->boolean('not_found');
                        if ($notFoundFilter) {
                            $builder->where('not_found', true);
                        }
                    })
                    ->get();

                // Load metadata relationships for search results
                if ($searchResults->isNotEmpty()) {
                    $searchResults->load(['metadata']);
                }
            } catch (\Exception $e) {
                // Fallback to database search if Scout fails (e.g., in testing)
                $searchResults = collect([]);
            }

            // If Scout search returns no results, fallback to database search
            if ($searchResults->isEmpty()) {
                $query->where(function ($q) use ($searchQuery) {
                    $q->where('filename', 'like', "%{$searchQuery}%")
                      ->orWhere('path', 'like', "%{$searchQuery}%")
                      ->orWhere('title', 'like', "%{$searchQuery}%");
                });

                // Apply not_found filter for database search
                $notFoundFilter = $request->boolean('not_found');
                if ($notFoundFilter) {
                    $query->where('not_found', true);
                }

                $searchResults = $query->get();

                // Load metadata relationships for database search results
                if ($searchResults->isNotEmpty()) {
                    $searchResults->load(['metadata']);
                }
            }

            // Transform search results to match the expected format
            $transformedResults = $searchResults->map(function ($file) {
                return [
                    'id' => $file->id,
                    'path' => $file->path,
                    'name' => $file->filename,
                    'type' => $file->ext,
                    'mime_type' => $file->mime_type,
                    'not_found' => $file->not_found,
                    'created_at' => $file->created_at,
                ];
            });

            // Create a paginated collection for search results
            $files = new \Illuminate\Pagination\LengthAwarePaginator(
                $transformedResults,
                $transformedResults->count(),
                20,
                1,
                ['path' => $request->url(), 'pageName' => 'page']
            );
            $files->withQueryString();
        } else {
            // Handle not_found filter for regular listing
            $notFoundFilter = $request->boolean('not_found');
            if ($notFoundFilter) {
                $query->where('not_found', true);
            }

            // Get regular files for listing when not searching
            $files = $query->select([
                    'id',
                    'path',
                    'filename as name',
                    'ext as type',
                    'mime_type',
                    'not_found',
                    'created_at'
                ])
                ->orderBy('created_at', 'desc')
                ->paginate(20)
                ->withQueryString();
        }

        return Inertia::render('Files/Index', [
            'files' => $files,
            'search' => $searchQuery, // Keep backward compatibility with tests
            'searchResults' => $searchResults, // Add search results for enhanced functionality
            'notFoundFilter' => $request->boolean('not_found'),
        ]);
    }

    /**
     * Mark a file as seen (preview or full view)
     */
    public function markAsSeen(Request $request, File $file)
    {
        $request->validate([
            'type' => 'required|in:preview,file'
        ]);

        $type = $request->input('type');
        $now = now();

        if ($type === 'preview' && !$file->seen_preview_at) {
            $file->update(['seen_preview_at' => $now]);
        } elseif ($type === 'file' && !$file->seen_file_at) {
            $file->update(['seen_file_at' => $now]);
        }

        return response()->json([
            'success' => true,
            'message' => 'File marked as seen',
            'type' => $type,
            'timestamp' => $now
        ]);
    }

    /**
     * Get the metadata for a specific file (for AJAX requests)
     */
    public function getMetadata(File $file)
    {
        $file->load('metadata');
        
        return response()->json([
            'fileId' => $file->id,
            'metadata' => $file->metadata,
            // Also include listing metadata so the UI can update without a full reload
            'listing_metadata' => $file->listing_metadata,
        ]);
    }

    /**
     * Remove the specified file from storage.
     */
    public function destroy(File $file)
    {
        // Check if the user is an admin
        if (!Auth::user()->is_admin) {
            abort(HttpResponse::HTTP_FORBIDDEN, 'Access denied. Only admins can delete files.');
        }

        $file->delete();

        return back()->with('success', 'File deleted successfully.');
    }

    // Reactions (moved from AudioController): also auto-queue download for positive reactions

    public function toggleLove(File $file)
    {
        $file->loved = !$file->loved;
        $file->loved_at = $file->loved ? now() : null;

        if ($file->loved) {
            $file->liked = false;
            $file->liked_at = null;
            $file->disliked = false;
            $file->disliked_at = null;
            $file->funny = false;
            $file->laughed_at = null;
            // Positive reaction should remove blacklist
            $file->is_blacklisted = false;
            $file->blacklist_reason = null;
        }

        $file->save();

        // Auto-download on positive reaction
        if ($file->loved && !$file->downloaded) {
            DownloadFile::dispatch($file);
        }

        if (request()->wantsJson()) {
            return response()->json([
                'loved' => $file->loved,
                'liked' => $file->liked,
                'disliked' => $file->disliked,
                'funny' => $file->funny,
            ]);
        }

        return back(303);
    }

    public function toggleLike(File $file)
    {
        $file->liked = !$file->liked;
        $file->liked_at = $file->liked ? now() : null;

        if ($file->liked) {
            $file->loved = false;
            $file->loved_at = null;
            $file->disliked = false;
            $file->disliked_at = null;
            $file->funny = false;
            $file->laughed_at = null;
            // Positive reaction should remove blacklist
            $file->is_blacklisted = false;
            $file->blacklist_reason = null;
        }

        $file->save();

        // Auto-download on positive reaction
        if ($file->liked && !$file->downloaded) {
            DownloadFile::dispatch($file);
        }

        if (request()->wantsJson()) {
            return response()->json([
                'loved' => $file->loved,
                'liked' => $file->liked,
                'disliked' => $file->disliked,
                'funny' => $file->funny,
            ]);
        }

        return back(303);
    }

    public function toggleDislike(File $file)
    {
        $file->disliked = !$file->disliked;
        $file->disliked_at = $file->disliked ? now() : null;

        if ($file->disliked) {
            $file->loved = false;
            $file->loved_at = null;
            $file->liked = false;
            $file->liked_at = null;
            $file->funny = false;
            $file->laughed_at = null;
        }

        $file->save();

        // No download on dislike/blacklist

        if (request()->wantsJson()) {
            return response()->json([
                'loved' => $file->loved,
                'liked' => $file->liked,
                'disliked' => $file->disliked,
                'funny' => $file->funny,
            ]);
        }

        return back(303);
    }

    public function toggleLaughedAt(File $file)
    {
        $file->funny = !$file->funny;
        $file->laughed_at = $file->funny ? now() : null;

        if ($file->funny) {
            $file->loved = false;
            $file->loved_at = null;
            $file->liked = false;
            $file->liked_at = null;
            $file->disliked = false;
            $file->disliked_at = null;
            // Positive reaction should remove blacklist
            $file->is_blacklisted = false;
            $file->blacklist_reason = null;
        }

        $file->save();

        // Auto-download on positive reaction
        if ($file->funny && !$file->downloaded) {
            DownloadFile::dispatch($file);
        }

        if (request()->wantsJson()) {
            return response()->json([
                'loved' => $file->loved,
                'liked' => $file->liked,
                'disliked' => $file->disliked,
                'funny' => $file->funny,
            ]);
        }

        return back(303);
    }
}
