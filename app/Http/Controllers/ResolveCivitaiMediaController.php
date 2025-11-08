<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Support\CivitaiMediaResolver;
use App\Support\FilePreviewUrl;
use Illuminate\Http\JsonResponse;

class ResolveCivitaiMediaController extends Controller
{
    public function __invoke(File $file, CivitaiMediaResolver $resolver): JsonResponse
    {
        if (strcasecmp((string) $file->source, 'CivitAI') !== 0) {
            return response()->json([
                'resolved' => false,
                'not_found' => false,
                'message' => 'Unsupported file source.',
            ], 422);
        }

        $resolution = $resolver->resolveAndUpdate($file);

        $file->refresh();

        $preview = FilePreviewUrl::for($file) ?? $file->thumbnail_url ?? $file->url;
        $type = $this->detectType($file->mime_type);

        return response()->json([
            'id' => $file->id,
            'resolved' => $resolution->found,
            'not_found' => $resolution->notFound,
            'updated' => $resolution->updated,
            'preview' => $preview,
            'original' => $file->url,
            'thumbnail_url' => $file->thumbnail_url,
            'true_original_url' => $file->url,
            'true_thumbnail_url' => $file->thumbnail_url ?? $preview,
            'mime_type' => $file->mime_type,
            'type' => $type,
        ]);
    }

    protected function detectType(?string $mime): string
    {
        $mime = (string) $mime;

        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }

        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }

        if (str_starts_with($mime, 'audio/')) {
            return 'audio';
        }

        return 'other';
    }
}
