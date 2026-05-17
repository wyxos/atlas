<?php

namespace App\Http\Controllers;

use App\Models\AlbumCover;
use App\Support\AtlasPathResolver;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AlbumCoverController extends Controller
{
    public function show(AlbumCover $albumCover): BinaryFileResponse
    {
        $resolvedPath = AtlasPathResolver::resolveExistingPath($albumCover->path);
        if (! $resolvedPath) {
            abort(404, 'Album cover not found');
        }

        return response()->file($resolvedPath['full_path'], [
            'Content-Type' => $albumCover->mime_type ?: 'image/jpeg',
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }
}
