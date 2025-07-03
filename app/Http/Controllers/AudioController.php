<?php

namespace App\Http\Controllers;

use App\Models\File;
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
        $file->load(['covers', 'artists', 'albums']);

        return Inertia::render('FileShow', [
            'file' => $file,
            'metadata' => $file->metadata,
            'rawMetadata' => Storage::disk('atlas')->json('metadata/'.$file->id.'.json'),
        ]);
    }

    public function getDetails(File $file)
    {
        // Load the covers, artists, and albums relationships
        $file->load(['metadata', 'covers', 'artists', 'albums']);

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
}
