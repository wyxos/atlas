<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ImageController extends Controller
{
    public function index()
    {
        $query = request()->input('query', '');

        // Use Scout search for all cases - use wildcard '*' for empty queries
        $searchQuery = empty($query) ? '*' : $query;

        $images = File::search($searchQuery)
            ->query(function (Builder $builder) {
                $builder
                    ->where('mime_type', 'like', 'image/%')
                    ->where('is_blacklisted', 0)
                    ->where('not_found', 0);
            })
            ->whereNotIn('path', ['__missing__'])
            ->orderBy('created_at', 'desc')
            ->paginate(48);

        // Append image_url attribute to results
        $images->getCollection()->transform(function ($file) {
            $file->append('image_url');
            return $file;
        });

        return Inertia::render('Images', [
            'images' => $images,
        ]);
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

    public function show(File $file)
    {
        // Load the covers and metadata relationships
        $file->load(['covers', 'metadata']);

        // Append the image_url attribute
        $file->append('image_url');

        return Inertia::render('ImageShow', [
            'file' => $file,
            'metadata' => $file->metadata,
            'rawMetadata' => Storage::disk('atlas')->json('metadata/'.$file->id.'.json'),
        ]);
    }
}
