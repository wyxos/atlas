<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AudioController extends Controller
{
    public function stream(int $id)
    {
        $file = File::findOrFail($id);

        // Ensure the file exists and is an audio file
        if (!file_exists($file->path) || !str_starts_with($file->mime_type, 'audio/')) {
            abort(404, 'Audio file not found');
        }

        // Stream the file with proper headers for audio streaming
        return response()->file($file->path, [
            'Content-Type' => $file->mime_type,
            'Content-Disposition' => 'inline; filename="' . $file->filename . '"',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    public function show(File $file)
    {
        return Inertia::render('FileShow', [
            'file' => $file,
            'metadata' => $file->metadata,
            'rawMetadata' => Storage::get('metadata/' . $file->id . '.json'),
        ]);
    }
}
