<?php

namespace App\Http\Controllers;

use App\Models\Cover;
use App\Models\File;
use App\Models\Artist;
use App\Models\Album;
use App\Models\Playlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class AudioController extends Controller
{
    public function stream(int $id)
    {
        $file = File::findOrFail($id);

        // Ensure the file exists and is an audio file
        $path = Storage::disk('atlas')->path($file->path);

        if (! file_exists($path) || ! str_starts_with($file->mime_type, 'audio/')) {
            abort(404, 'Audio file not found');
        }

        // Stream the file with proper headers for audio streaming
        return response()->file($path, [
            'Content-Type' => $file->mime_type,
            'Content-Disposition' => 'inline; filename="'.$file->filename.'"',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    public function show(File $file)
    {
        // Load the covers, artists, and albums relationships
        $file->load(['covers', 'artists.covers', 'albums.covers']);

        return Inertia::render('FileShow', [
            'file' => $file,
            'metadata' => $file->metadata,
            'rawMetadata' => Storage::disk('atlas')->json('metadata/'.$file->id.'.json'),
        ]);
    }

    public function getDetails(File $file)
    {
        // Load the covers, artists, and albums relationships
        $file->load(['metadata', 'covers', 'artists.covers', 'albums.covers']);

        return response()->json($file);
    }

    public function getBatchDetails(Request $request)
    {
        $request->validate([
            'file_ids' => 'required|array',
            'file_ids.*' => 'required|integer|exists:files,id'
        ]);

        $fileIds = $request->input('file_ids');

        // Load all files with their relationships in a single query
        $files = File::whereIn('id', $fileIds)
            ->with(['metadata', 'covers', 'artists.covers', 'albums.covers'])
            ->get()
            ->keyBy('id'); // Key by ID for easy frontend access

        return response()->json($files);
    }

    public function toggleLove(File $file)
    {
        $file->loved = !$file->loved;
        $file->loved_at = $file->loved ? now() : null;

        // Reset other statuses when loving
        if ($file->loved) {
            $file->liked = false;
            $file->liked_at = null;
            $file->disliked = false;
            $file->disliked_at = null;
            $file->funny = false;
            $file->laughed_at = null;
        }

        $file->save();

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

        // Reset other statuses when liking
        if ($file->liked) {
            $file->loved = false;
            $file->loved_at = null;
            $file->disliked = false;
            $file->disliked_at = null;
            $file->funny = false;
            $file->laughed_at = null;
        }

        $file->save();

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

        // Reset other statuses when disliking
        if ($file->disliked) {
            $file->loved = false;
            $file->loved_at = null;
            $file->liked = false;
            $file->liked_at = null;
            $file->funny = false;
            $file->laughed_at = null;
        }

        $file->save();

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

        // Reset other statuses when laughing at
        if ($file->funny) {
            $file->loved = false;
            $file->loved_at = null;
            $file->liked = false;
            $file->liked_at = null;
            $file->disliked = false;
            $file->disliked_at = null;
        }

        $file->save();

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

    public function updateCover(Request $request, int $coverId)
    {
        $request->validate([
            'file' => 'required|image|mimes:jpeg,png,jpg,gif,webp',
        ]);

        // Find the specific cover by ID
        $oldCover = Cover::find($coverId);

        if (!$oldCover) {
            return back()->withErrors(['cover' => 'Cover not found']);
        }

        // Store the old path for deletion
        $oldPath = $oldCover->path;

        // Store the new cover image
        $uploadedFile = $request->file('file');
        $hash = md5(file_get_contents($uploadedFile->getPathname()));

        // Generate a random 40-character filename like in extract metadata
        $extension = $uploadedFile->getClientOriginalExtension();
        $randomFilename = Str::random(40);
        $newPath = "covers/{$randomFilename}.{$extension}";

        // Store the file
        Storage::disk('atlas')->put($newPath, file_get_contents($uploadedFile->getPathname()));

        // Update the existing cover with new image data
        $oldCover->path = $newPath;
        $oldCover->hash = $hash;
        $oldCover->save();

        // Delete the old cover file
        Storage::disk('atlas')->delete($oldPath);

        return back()->with('success', 'Cover updated successfully');
    }

    public function createCover(Request $request, int $fileId)
    {
        $request->validate([
            'file' => 'required|image|mimes:jpeg,png,jpg,gif,webp',
        ]);

        // Find the file
        $file = File::with(['albums'])->find($fileId);

        if (!$file) {
            return back()->withErrors(['file' => 'File not found']);
        }

        // Store the new cover image
        $uploadedFile = $request->file('file');
        $hash = md5(file_get_contents($uploadedFile->getPathname()));

        // Generate a random 40-character filename like in extract metadata
        $extension = $uploadedFile->getClientOriginalExtension();
        $randomFilename = Str::random(40);
        $newPath = "covers/{$randomFilename}.{$extension}";

        // Store the file
        Storage::disk('atlas')->put($newPath, file_get_contents($uploadedFile->getPathname()));

        // Determine where to attach the cover
        // Priority: album if exists, otherwise file itself
        if ($file->albums && $file->albums->count() > 0) {
            // Attach to the first album
            $album = $file->albums->first();
            $cover = Cover::create([
                'path' => $newPath,
                'hash' => $hash,
                'coverable_id' => $album->id,
                'coverable_type' => Album::class,
            ]);
        } else {
            // Attach to the file itself
            $cover = Cover::create([
                'path' => $newPath,
                'hash' => $hash,
                'coverable_id' => $file->id,
                'coverable_type' => File::class,
            ]);
        }

        return back()->with('success', 'Cover created successfully');
    }

    public function favorites()
    {
        $search = [];

        if ($query = request()->input('query')) {
            // Filter search results to only include audio files that exist and are loved
            $search = File::search($query)
                ->query(function ($builder) {
                    $builder->where('mime_type', 'like', 'audio/%')
                            ->where('not_found', false)
                            ->where('loved', true);
                })
                ->get();

            // Load metadata, covers, artists, and albums relationships for search results
            if ($search->isNotEmpty()) {
                $search->load(['metadata', 'covers', 'artists', 'albums']);
            }
        }

        return Inertia::render('Audio', [
            'files' => fn () => File::audio()
                ->where('not_found', false)
                ->where('loved', true)
                ->select(['id'])
                ->get(),
            'search' => $search,
            'title' => 'Favorites',
        ]);
    }

    public function liked()
    {
        $search = [];

        if ($query = request()->input('query')) {
            // Filter search results to only include audio files that exist and are liked
            $search = File::search($query)
                ->query(function ($builder) {
                    $builder->where('mime_type', 'like', 'audio/%')
                            ->where('not_found', false)
                            ->where('liked', true);
                })
                ->get();

            // Load metadata, covers, artists, and albums relationships for search results
            if ($search->isNotEmpty()) {
                $search->load(['metadata', 'covers', 'artists', 'albums']);
            }
        }

        return Inertia::render('Audio', [
            'files' => fn () => File::audio()
                ->where('not_found', false)
                ->where('liked', true)
                ->select(['id'])
                ->get(),
            'search' => $search,
            'title' => 'Liked',
        ]);
    }

    public function disliked()
    {
        $search = [];

        if ($query = request()->input('query')) {
            // Filter search results to only include audio files that exist and are disliked
            $search = File::search($query)
                ->query(function ($builder) {
                    $builder->where('mime_type', 'like', 'audio/%')
                            ->where('not_found', false)
                            ->where('disliked', true);
                })
                ->get();

            // Load metadata, covers, artists, and albums relationships for search results
            if ($search->isNotEmpty()) {
                $search->load(['metadata', 'covers', 'artists', 'albums']);
            }
        }

        return Inertia::render('Audio', [
            'files' => fn () => File::audio()
                ->where('not_found', false)
                ->where('disliked', true)
                ->select(['id'])
                ->get(),
            'search' => $search,
            'title' => 'Disliked',
        ]);
    }

    public function unrated()
    {
        $search = [];

        if ($query = request()->input('query')) {
            // Filter search results to only include audio files that exist and are unrated
            $search = File::search($query)
                ->query(function ($builder) {
                    $builder->where('mime_type', 'like', 'audio/%')
                            ->where('not_found', false)
                            ->where('loved', false)
                            ->where('liked', false)
                            ->where('disliked', false)
                            ->where('funny', false);
                })
                ->get();

            // Load metadata, covers, artists, and albums relationships for search results
            if ($search->isNotEmpty()) {
                $search->load(['metadata', 'covers', 'artists', 'albums']);
            }
        }

        return Inertia::render('Audio', [
            'files' => fn () => File::audio()
                ->where('not_found', false)
                ->where('loved', false)
                ->where('liked', false)
                ->where('disliked', false)
                ->where('funny', false)
                ->select(['id'])
                ->get(),
            'search' => $search,
            'title' => 'Unrated',
        ]);
    }

    public function funny()
    {
        $search = [];

        if ($query = request()->input('query')) {
            // Filter search results to only include audio files that exist and are funny
            $search = File::search($query)
                ->query(function ($builder) {
                    $builder->where('mime_type', 'like', 'audio/%')
                            ->where('not_found', false)
                            ->where('funny', true);
                })
                ->get();

            // Load metadata, covers, artists, and albums relationships for search results
            if ($search->isNotEmpty()) {
                $search->load(['metadata', 'covers', 'artists', 'albums']);
            }
        }

        return Inertia::render('Audio', [
            'files' => fn () => File::audio()
                ->where('not_found', false)
                ->where('funny', true)
                ->select(['id'])
                ->get(),
            'search' => $search,
            'title' => 'Funny',
        ]);
    }

    public function podcasts()
    {
        $search = [];

        if ($query = request()->input('query')) {
            // Filter search results to only include audio files that exist and are podcasts
            $search = File::search($query)
                ->query(function ($builder) {
                    $builder->where('mime_type', 'like', 'audio/%')
                            ->where('not_found', false)
                            ->where(function ($query) {
                                $query->whereHas('metadata', function ($metaQuery) {
                                    $metaQuery->where('genre', 'like', '%podcast%')
                                             ->orWhere('genre', 'like', '%Podcast%')
                                             ->orWhere('album', 'like', '%podcast%')
                                             ->orWhere('album', 'like', '%Podcast%');
                                })
                                ->orWhere('path', 'like', '%podcast%')
                                ->orWhere('path', 'like', '%Podcast%');
                            });
                })
                ->get();

            // Load metadata, covers, artists, and albums relationships for search results
            if ($search->isNotEmpty()) {
                $search->load(['metadata', 'covers', 'artists', 'albums']);
            }
        }

        return Inertia::render('Audio', [
            'files' => fn () => File::audio()
                ->where('not_found', false)
                ->where(function ($query) {
                    $query->whereHas('metadata', function ($metaQuery) {
                        $metaQuery->where('genre', 'like', '%podcast%')
                                 ->orWhere('genre', 'like', '%Podcast%')
                                 ->orWhere('album', 'like', '%podcast%')
                                 ->orWhere('album', 'like', '%Podcast%');
                    })
                    ->orWhere('path', 'like', '%podcast%')
                    ->orWhere('path', 'like', '%Podcast%');
                })
                ->select(['id'])
                ->get(),
            'search' => $search,
            'title' => 'Podcasts',
        ]);
    }

    public function artists()
    {
        $search = [];

        if ($query = request()->input('query')) {
            // Filter search results to only include artists with audio files
            $search = Artist::search($query)
                ->query(function ($builder) {
                    $builder->whereHas('files', function ($query) {
                        $query->audio()->where('not_found', false);
                    });
                })
                ->get();

            // Load covers relationship for search results
            if ($search->isNotEmpty()) {
                $search->load(['covers']);
            }
        }

        $artists = Artist::whereHas('files', function ($query) {
            $query->audio()->where('not_found', false);
        })->with('covers')
        ->orderBy('name')
        ->paginate(12);

        return Inertia::render('Artists', [
            'artists' => $artists,
            'search' => $search,
        ]);
    }

    public function albums()
    {
        $search = [];

        if ($query = request()->input('query')) {
            // Filter search results to only include albums with audio files
            $search = Album::search($query)
                ->query(function ($builder) {
                    $builder->whereHas('files', function ($query) {
                        $query->audio()->where('not_found', false);
                    });
                })
                ->get();

            // Load covers relationship for search results
            if ($search->isNotEmpty()) {
                $search->load(['covers']);
            }
        }

        $albums = Album::whereHas('files', function ($query) {
            $query->audio()->where('not_found', false);
        })->with('covers')
        ->orderBy('name')
        ->paginate(12);

        return Inertia::render('Albums', [
            'albums' => $albums,
            'search' => $search,
        ]);
    }

    public function playlists()
    {
        $playlists = Playlist::withCount('files')
        ->orderBy('created_at', 'desc')
        ->paginate(10);

        return Inertia::render('Playlists', [
            'playlists' => $playlists,
        ]);
    }

    public function storePlaylist(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        Playlist::create([
            'name' => $request->name,
            'user_id' => auth()->id(),
        ]);

        return back()->with('success', 'Playlist created successfully');
    }

    public function showPlaylist(Playlist $playlist)
    {
        $search = [];

        if ($query = request()->input('query')) {
            // Get playlist file IDs for filtering
            $playlistFileIds = $playlist->files()
                ->where('mime_type', 'like', 'audio/%')
                ->where('not_found', false)
                ->pluck('files.id')
                ->toArray();

            // Search files and filter to only include files in this playlist
            $search = File::search($query)
                ->query(function ($builder) use ($playlistFileIds) {
                    $builder->whereIn('id', $playlistFileIds)
                            ->where('mime_type', 'like', 'audio/%')
                            ->where('not_found', false);
                })
                ->get();

            // Load metadata, covers, artists, and albums relationships for search results
            if ($search->isNotEmpty()) {
                $search->load(['metadata', 'covers', 'artists', 'albums']);
            }
        }

        return Inertia::render('PlaylistShow', [
            'playlist' => $playlist->load(['user', 'covers']),
            'files' => fn () => $playlist->files()
                ->where('mime_type', 'like', 'audio/%')
                ->where('not_found', false)
                ->select(['files.id'])
                ->get(),
            'search' => $search,
        ]);
    }

    public function addFileToPlaylist(Request $request, Playlist $playlist)
    {
        $request->validate([
            'file_id' => 'required|exists:files,id',
        ]);

        $file = File::findOrFail($request->file_id);

        // Check if file is already in playlist
        if ($playlist->files()->where('files.id', $file->id)->exists()) {
            // Remove file from playlist (toggle off)
            $playlist->files()->detach($file->id);
            return back()->with('success', 'Track removed from playlist successfully');
        }

        // Add file to playlist (toggle on)
        $playlist->files()->attach($file->id);

        return back()->with('success', 'Track added to playlist successfully');
    }

    public function getFilePlaylistMembership(File $file)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json([]);
        }

        $playlistIds = $file->playlists()
            ->where('user_id', $user->id)
            ->pluck('playlists.id')
            ->toArray();

        return response()->json($playlistIds);
    }
}
