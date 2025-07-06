<?php

namespace App\Http\Controllers;

use App\Models\Cover;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

    public function updateCover(Request $request, File $file, int $coverIndex)
    {
        $request->validate([
            'cover' => 'required|image|mimes:jpeg,png,jpg,gif,webp', // 10MB max
        ]);

        // Load covers to get the current cover at the specified index
        $file->load('covers');

        if (!isset($file->covers[$coverIndex])) {
            return back()->withErrors(['cover' => 'Invalid cover index']);
        }

        $oldCover = $file->covers[$coverIndex];

        // Store the new cover image
        $uploadedFile = $request->file('cover');
        $hash = md5(file_get_contents($uploadedFile->getPathname()));

        // Generate a unique filename
        $extension = $uploadedFile->getClientOriginalExtension();
        $filename = $hash . '.' . $extension;
        $path = 'covers/' . $filename;

        // Store the file
        $storedPath = $uploadedFile->storeAs('covers', $filename, 'public');

        if (!$storedPath) {
            return back()->withErrors(['cover' => 'Failed to store cover image']);
        }

        // Store the old path for deletion
        $oldPath = $oldCover->path;

        // Update the existing cover with new image data
        $oldCover->path = $storedPath;
        $oldCover->hash = $hash;
        $oldCover->save();

        // Delete the old cover file
        Storage::disk('public')->delete($oldPath);

        return back()->with('success', 'Cover updated successfully');
    }
}
