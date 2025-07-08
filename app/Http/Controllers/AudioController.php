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
        }

        $file->save();

        if (request()->wantsJson()) {
            return response()->json([
                'loved' => $file->loved,
                'liked' => $file->liked,
                'disliked' => $file->disliked,
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
        }

        $file->save();

        if (request()->wantsJson()) {
            return response()->json([
                'loved' => $file->loved,
                'liked' => $file->liked,
                'disliked' => $file->disliked,
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
        }

        $file->save();

        if (request()->wantsJson()) {
            return response()->json([
                'loved' => $file->loved,
                'liked' => $file->liked,
                'disliked' => $file->disliked,
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
        return Inertia::render('Audio', [
            'files' => fn () => File::audio()
                ->where('not_found', false)
                ->where('loved', true)
                ->select(['id'])
                ->get(),
            'search' => [],
            'title' => 'Favorites',
        ]);
    }

    public function liked()
    {
        return Inertia::render('Audio', [
            'files' => fn () => File::audio()
                ->where('not_found', false)
                ->where('liked', true)
                ->select(['id'])
                ->get(),
            'search' => [],
            'title' => 'Liked',
        ]);
    }

    public function disliked()
    {
        return Inertia::render('Audio', [
            'files' => fn () => File::audio()
                ->where('not_found', false)
                ->where('disliked', true)
                ->select(['id'])
                ->get(),
            'search' => [],
            'title' => 'Disliked',
        ]);
    }

    public function unrated()
    {
        return Inertia::render('Audio', [
            'files' => fn () => File::audio()
                ->where('not_found', false)
                ->where('loved', false)
                ->where('liked', false)
                ->where('disliked', false)
                ->select(['id'])
                ->get(),
            'search' => [],
            'title' => 'Unrated',
        ]);
    }

    public function artists()
    {
        $artists = Artist::whereHas('files', function ($query) {
            $query->audio()->where('not_found', false);
        })->with('covers')->get();

        return Inertia::render('Audio', [
            'files' => collect([]), // Empty collection for now
            'search' => [],
            'title' => 'Artists',
            'artists' => $artists,
        ]);
    }

    public function albums()
    {
        $albums = Album::whereHas('files', function ($query) {
            $query->audio()->where('not_found', false);
        })->with('covers')->get();

        return Inertia::render('Audio', [
            'files' => collect([]), // Empty collection for now
            'search' => [],
            'title' => 'Albums',
            'albums' => $albums,
        ]);
    }

    public function playlists()
    {
        $playlists = Playlist::whereHas('files', function ($query) {
            $query->audio()->where('not_found', false);
        })->get();

        return Inertia::render('Audio', [
            'files' => collect([]), // Empty collection for now
            'search' => [],
            'title' => 'Playlists',
            'playlists' => $playlists,
        ]);
    }
}
