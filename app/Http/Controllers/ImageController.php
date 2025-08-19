<?php

namespace App\Http\Controllers;

use App\Models\File;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ImageController extends Controller
{
    public function index()
    {

        return Inertia::render('Images');
    }

    public function books()
    {
        $search = [];

        if ($query = request()->input('query')) {
            // Filter search results to only include image files that exist and are books
            $search = File::search($query)
                ->query(function ($builder) {
                    $builder->where('mime_type', 'like', 'image/%')
                        ->where('not_found', false)
                        ->where(function ($q) {
                            $q->whereJsonContains('tags', 'book')
                                ->orWhere('title', 'like', '%book%')
                                ->orWhere('title', 'like', '%page%')
                                ->orWhere('filename', 'like', '%book%')
                                ->orWhere('filename', 'like', '%page%')
                                ->orWhere('filename', 'like', '%chapter%');
                        });
                })
                ->get();

            // Load covers relationship for search results
            if ($search->isNotEmpty()) {
                $search->load(['covers']);
            }
        }

        $images = File::image()
            ->where('not_found', false)
            ->where(function ($query) {
                $query->whereJsonContains('tags', 'book')
                    ->orWhere('title', 'like', '%book%')
                    ->orWhere('title', 'like', '%page%')
                    ->orWhere('filename', 'like', '%book%')
                    ->orWhere('filename', 'like', '%page%')
                    ->orWhere('filename', 'like', '%chapter%');
            })
            ->with('covers')
            ->orderBy('created_at', 'desc')
            ->paginate(48);

        // Append image_url attribute to paginated results
        $images->getCollection()->transform(function ($file) {
            $file->append('image_url');
            return $file;
        });

        // Append image_url attribute to search results
        if (!empty($search)) {
            $search = collect($search)->map(function ($file) {
                $file->append('image_url');
                return $file;
            })->toArray();
        }

        return Inertia::render('Images', [
            'images' => $images,
            'search' => $search,
            'title' => 'Books',
        ]);
    }

    public function sets()
    {
        $search = [];

        if ($query = request()->input('query')) {
            // Filter search results to only include image files that exist and are sets
            $search = File::search($query)
                ->query(function ($builder) {
                    $builder->where('mime_type', 'like', 'image/%')
                        ->where('not_found', false)
                        ->where(function ($q) {
                            $q->whereJsonContains('tags', 'set')
                                ->orWhere('title', 'like', '%set%')
                                ->orWhere('title', 'like', '%collection%')
                                ->orWhere('title', 'like', '%gallery%')
                                ->orWhere('filename', 'like', '%set%')
                                ->orWhere('filename', 'like', '%collection%')
                                ->orWhere('filename', 'like', '%gallery%');
                        });
                })
                ->get();

            // Load covers relationship for search results
            if ($search->isNotEmpty()) {
                $search->load(['covers']);
            }
        }

        $images = File::image()
            ->where('not_found', false)
            ->where(function ($query) {
                $query->whereJsonContains('tags', 'set')
                    ->orWhere('title', 'like', '%set%')
                    ->orWhere('title', 'like', '%collection%')
                    ->orWhere('title', 'like', '%gallery%')
                    ->orWhere('filename', 'like', '%set%')
                    ->orWhere('filename', 'like', '%collection%')
                    ->orWhere('filename', 'like', '%gallery%');
            })
            ->with('covers')
            ->orderBy('created_at', 'desc')
            ->paginate(48);

        // Append image_url attribute to paginated results
        $images->getCollection()->transform(function ($file) {
            $file->append('image_url');
            return $file;
        });

        // Append image_url attribute to search results
        if (!empty($search)) {
            $search = collect($search)->map(function ($file) {
                $file->append('image_url');
                return $file;
            })->toArray();
        }

        return Inertia::render('Images', [
            'images' => $images,
            'search' => $search,
            'title' => 'Sets',
        ]);
    }

    public function various()
    {
        $search = [];

        if ($query = request()->input('query')) {
            // Filter search results to only include image files that exist and are various (not books or sets)
            $search = File::search($query)
                ->query(function ($builder) {
                    $builder->where('mime_type', 'like', 'image/%')
                        ->where('not_found', false)
                        ->where(function ($q) {
                            $q->whereJsonDoesntContain('tags', 'book')
                                ->whereJsonDoesntContain('tags', 'set');
                        })
                        ->where('title', 'not like', '%book%')
                        ->where('title', 'not like', '%page%')
                        ->where('title', 'not like', '%set%')
                        ->where('title', 'not like', '%collection%')
                        ->where('title', 'not like', '%gallery%')
                        ->where('filename', 'not like', '%book%')
                        ->where('filename', 'not like', '%page%')
                        ->where('filename', 'not like', '%chapter%')
                        ->where('filename', 'not like', '%set%')
                        ->where('filename', 'not like', '%collection%')
                        ->where('filename', 'not like', '%gallery%');
                })
                ->get();

            // Load covers relationship for search results
            if ($search->isNotEmpty()) {
                $search->load(['covers']);
            }
        }

        $images = File::image()
            ->where('not_found', false)
            ->where(function ($query) {
                $query->whereJsonDoesntContain('tags', 'book')
                    ->whereJsonDoesntContain('tags', 'set');
            })
            ->where('title', 'not like', '%book%')
            ->where('title', 'not like', '%page%')
            ->where('title', 'not like', '%set%')
            ->where('title', 'not like', '%collection%')
            ->where('title', 'not like', '%gallery%')
            ->where('filename', 'not like', '%book%')
            ->where('filename', 'not like', '%page%')
            ->where('filename', 'not like', '%chapter%')
            ->where('filename', 'not like', '%set%')
            ->where('filename', 'not like', '%collection%')
            ->where('filename', 'not like', '%gallery%')
            ->with('covers')
            ->orderBy('created_at', 'desc')
            ->paginate(48);

        // Append image_url attribute to paginated results
        $images->getCollection()->transform(function ($file) {
            $file->append('image_url');
            return $file;
        });

        // Append image_url attribute to search results
        if (!empty($search)) {
            $search = collect($search)->map(function ($file) {
                $file->append('image_url');
                return $file;
            })->toArray();
        }

        return Inertia::render('Images', [
            'images' => $images,
            'search' => $search,
            'title' => 'Various',
        ]);
    }

    public function blacklisted()
    {
        return Inertia::render('BlacklistedImages');
    }

    public function unrated()
    {
        return Inertia::render('UnratedImages');
    }

    public function data(Request $request)
    {
        $page = (int)$request->get('page', 1);
        $limit = (int)$request->get('limit', 40);

        $paginator = File::search('*')
            ->whereNotIn('path', ['__missing__'])
            ->orderByDesc('created_at')
            ->paginate(perPage: $limit, page: $page);

        // Transform items to include image_url and any lightweight fields Masonry might use
        $items = $paginator->getCollection()->map(function (File $file) {
            return [
                'id' => $file->id,
                'src' => Storage::disk('atlas')->url($file->path),
                'width' => $file->metadata->payload['width'] ?? 0,
                'height' => $file->metadata->payload['height'] ?? 0,
            ];
        })->values();

        $hasMore = $paginator->hasMorePages();
        $nextPage = $hasMore ? ($page + 1) : null;

        return Inertia::render('Images', [
            'items' => $items,
            'filters' => [
                'page' => $page,
                'nextPage' => $nextPage,
                'limit' => $limit,
            ],
        ]);
    }

    public function blacklistedData(Request $request)
    {
        $page = (int)$request->get('page', 1);
        $limit = (int)$request->get('limit', 40);

        $paginator = File::search('*')
            ->where('is_blacklisted', true)
            ->orderByDesc('updated_at')
            ->paginate(perPage: $limit, page: $page);

        $items = $paginator->getCollection()->map(function (File $file) {
            $file->append('image_url');
            return [
                'id' => $file->id,
                'src' => $file->thumbnail_url,
                'width' => $file->metadata->payload['width'] ?? 0,
                'height' => $file->metadata->payload['height'] ?? 0,
            ];
        })->values();

        $nextPage = $paginator->hasMorePages() ? ($page + 1) : null;

        return Inertia::render('BlacklistedImages', [
            'items' => $items,
            'filters' => [
                'page' => $page,
                'nextPage' => $nextPage,
                'limit' => $limit,
            ],
        ]);
    }

    public function unratedData(Request $request)
    {
        $page = (int)$request->get('page', 1);
        $limit = (int)$request->get('limit', 40);

        $paginator = File::search('*')
            ->whereIn('path', ['__missing__'])
            ->where('is_blacklisted', false)
            ->where('downloaded', false)
            ->where('loved', false)
            ->where('disliked', false)
            ->where('liked', false)
            ->where('funny', false)
            ->orderByDesc('created_at')
            ->paginate(perPage: $limit, page: $page);

$items = $paginator->getCollection()->map(function (File $file) {
            $file->append('image_url');
            // Attempt to get dimensions from available metadata
            $width = data_get($file->detail_metadata, 'width')
                ?? data_get($file->listing_metadata, 'width')
                ?? ($file->metadata->payload['width'] ?? null);
            $height = data_get($file->detail_metadata, 'height')
                ?? data_get($file->listing_metadata, 'height')
                ?? ($file->metadata->payload['height'] ?? null);

            return [
                'id' => $file->id,
                'name' => $file->title ?: $file->filename,
                'mime_type' => $file->mime_type,
                // Thumbnail/preview image (like Browse items)
                'src' => $file->thumbnail_url ?: $file->image_url ?: $file->url,
                // Full image/video URL for the viewer (useImageZoom prioritizes 'original')
                'original' => $file->url,
                // Keep for compatibility
                'image_url' => $file->image_url,
                'url' => $file->url,
                'downloaded' => (bool) $file->downloaded,
                'path' => $file->path,
                'seen_preview_at' => $file->seen_preview_at ?? null,
                'seen_file_at' => $file->seen_file_at ?? null,
                'source' => $file->source,
                'liked' => (bool) $file->liked,
                'loved' => (bool) $file->loved,
                'disliked' => (bool) $file->disliked,
                'funny' => (bool) $file->funny,
                'width' => (int) ($width ?? 0),
                'height' => (int) ($height ?? 0),
                // For components that expect imageHeight like BrowseItem
                'imageHeight' => (int) ($height ?? 0),
            ];
        })->values();

        $nextPage = $paginator->hasMorePages() ? ($page + 1) : null;

        return Inertia::render('UnratedImages', [
            'items' => $items,
            'filters' => [
                'page' => $page,
                'nextPage' => $nextPage,
                'limit' => $limit,
            ],
        ]);
    }

    public function show(File $file)
    {
        // Load the covers and metadata relationships
        $file->load(['covers', 'metadata']);

        // Append the image_url attribute
        $file->append('image_url');

        return Inertia::render('ImageShow', [
            'file' => $file,
            'metadata' => $file->metadata,
            'rawMetadata' => Storage::disk('atlas')->json('metadata/' . $file->id . '.json'),
        ]);
    }

    /**
     * Delete a local file or blacklist a remote file
     */
    public function deleteOrBlacklist(File $file)
    {
        // If the file source is not 'local' (i.e., it's from CivitAI or other external sources), blacklist it
        if ($file->source !== 'local') {
            // Remove the physical file if it exists (for cached files)
            if ($file->path && Storage::disk('atlas')->exists($file->path)) {
                Storage::disk('atlas')->delete($file->path);
            }

            $file->update([
                'is_blacklisted' => true,
                'blacklist_reason' => 'Blacklisted from images page'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Image has been blacklisted',
                'action' => 'blacklisted'
            ]);
        }

        // For local files, actually delete them
        try {
            // Delete the physical file if it exists
            if ($file->path && Storage::disk('atlas')->exists($file->path)) {
                Storage::disk('atlas')->delete($file->path);
            }

            // Delete the database record
            $file->delete();

            return response()->json([
                'success' => true,
                'message' => 'Image has been deleted',
                'action' => 'deleted'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete image: ' . $e->getMessage()
            ], 500);
        }
    }
}
