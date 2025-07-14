<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\Request;
use Inertia\Inertia;

class VideoController extends Controller
{
    public function index()
    {
        $search = [];

        if ($query = request()->input('query')) {
            // Filter search results to only include video files that exist
            $search = File::search($query)
                ->query(function ($builder) {
                    $builder->where('mime_type', 'like', 'video/%')
                            ->where('not_found', false);
                })
                ->get();

            // Load covers relationship for search results
            if ($search->isNotEmpty()) {
                $search->load(['covers']);
            }
        }

        $videos = File::video()
            ->where('not_found', false)
            ->with('covers')
            ->orderBy('created_at', 'desc')
            ->paginate(24);

        return Inertia::render('Video', [
            'videos' => $videos,
            'search' => $search,
        ]);
    }

    public function movies()
    {
        $search = [];

        if ($query = request()->input('query')) {
            // Filter search results to only include video files that exist and are movies
            $search = File::search($query)
                ->query(function ($builder) {
                    $builder->where('mime_type', 'like', 'video/%')
                            ->where('not_found', false)
                            ->where(function ($q) {
                                $q->whereJsonContains('tags', 'movie')
                                  ->orWhere('title', 'like', '%movie%')
                                  ->orWhere('filename', 'like', '%movie%');
                            });
                })
                ->get();

            // Load covers relationship for search results
            if ($search->isNotEmpty()) {
                $search->load(['covers']);
            }
        }

        $videos = File::video()
            ->where('not_found', false)
            ->where(function ($query) {
                $query->whereJsonContains('tags', 'movie')
                      ->orWhere('title', 'like', '%movie%')
                      ->orWhere('filename', 'like', '%movie%');
            })
            ->with('covers')
            ->orderBy('created_at', 'desc')
            ->paginate(24);

        return Inertia::render('Video', [
            'videos' => $videos,
            'search' => $search,
            'title' => 'Movies',
        ]);
    }

    public function series()
    {
        $search = [];

        if ($query = request()->input('query')) {
            // Filter search results to only include video files that exist and are series
            $search = File::search($query)
                ->query(function ($builder) {
                    $builder->where('mime_type', 'like', 'video/%')
                            ->where('not_found', false)
                            ->where(function ($q) {
                                $q->whereJsonContains('tags', 'series')
                                  ->orWhere('title', 'like', '%series%')
                                  ->orWhere('title', 'like', '%episode%')
                                  ->orWhere('filename', 'like', '%series%')
                                  ->orWhere('filename', 'like', '%episode%')
                                  ->orWhere('filename', 'like', '%s[0-9][0-9]e[0-9][0-9]%');
                            });
                })
                ->get();

            // Load covers relationship for search results
            if ($search->isNotEmpty()) {
                $search->load(['covers']);
            }
        }

        $videos = File::video()
            ->where('not_found', false)
            ->where(function ($query) {
                $query->whereJsonContains('tags', 'series')
                      ->orWhere('title', 'like', '%series%')
                      ->orWhere('title', 'like', '%episode%')
                      ->orWhere('filename', 'like', '%series%')
                      ->orWhere('filename', 'like', '%episode%')
                      ->orWhere('filename', 'like', '%s[0-9][0-9]e[0-9][0-9]%');
            })
            ->with('covers')
            ->orderBy('created_at', 'desc')
            ->paginate(24);

        return Inertia::render('Video', [
            'videos' => $videos,
            'search' => $search,
            'title' => 'Series',
        ]);
    }

    public function various()
    {
        $search = [];

        if ($query = request()->input('query')) {
            // Filter search results to only include video files that exist and are various (not movies or series)
            $search = File::search($query)
                ->query(function ($builder) {
                    $builder->where('mime_type', 'like', 'video/%')
                            ->where('not_found', false)
                            ->where(function ($q) {
                                $q->whereJsonDoesntContain('tags', 'movie')
                                  ->whereJsonDoesntContain('tags', 'series');
                            })
                            ->where('title', 'not like', '%movie%')
                            ->where('title', 'not like', '%series%')
                            ->where('title', 'not like', '%episode%')
                            ->where('filename', 'not like', '%movie%')
                            ->where('filename', 'not like', '%series%')
                            ->where('filename', 'not like', '%episode%')
                            ->where('filename', 'not like', '%s[0-9][0-9]e[0-9][0-9]%');
                })
                ->get();

            // Load covers relationship for search results
            if ($search->isNotEmpty()) {
                $search->load(['covers']);
            }
        }

        $videos = File::video()
            ->where('not_found', false)
            ->where(function ($query) {
                $query->whereJsonDoesntContain('tags', 'movie')
                      ->whereJsonDoesntContain('tags', 'series');
            })
            ->where('title', 'not like', '%movie%')
            ->where('title', 'not like', '%series%')
            ->where('title', 'not like', '%episode%')
            ->where('filename', 'not like', '%movie%')
            ->where('filename', 'not like', '%series%')
            ->where('filename', 'not like', '%episode%')
            ->where('filename', 'not like', '%s[0-9][0-9]e[0-9][0-9]%')
            ->with('covers')
            ->orderBy('created_at', 'desc')
            ->paginate(24);

        return Inertia::render('Video', [
            'videos' => $videos,
            'search' => $search,
            'title' => 'Various',
        ]);
    }

    public function show(File $file)
    {
        // This method can be implemented later for individual video viewing
        return Inertia::render('VideoShow', [
            'file' => $file->load(['covers', 'metadata']),
        ]);
    }
}
