<?php

namespace App\Http\Controllers;

use App\Http\Resources\FileResource;
use App\Jobs\GenerateFilePreviewAssets;
use App\Models\File;
use App\Services\FileStorageResponseService;
use App\Support\FilePreviewGeneration;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class FilePreviewAssetsController extends Controller
{
    public function __construct(
        private readonly FileStorageResponseService $fileStorageResponses,
    ) {}

    public function __invoke(File $file): JsonResponse
    {
        abort_unless(Auth::check(), 403);

        if (! $file->path || (! $file->downloaded && $file->imported_at === null)) {
            return response()->json([
                'message' => 'Preview assets can only be generated for stored files.',
            ], 422);
        }

        GenerateFilePreviewAssets::dispatch((int) $file->id, true);

        $this->fileStorageResponses->loadViewerRelations($file);
        $this->fileStorageResponses->hydrateDiskMetadata($file);

        $resource = (new FileResource($file))->toArray(request());
        $resource['preview_generation'] = [
            ...((array) (FilePreviewGeneration::state($file) ?? [])),
            'status' => 'queued',
            'can_retry' => false,
            'message' => null,
        ];

        return response()->json([
            'queued' => true,
            'file' => $resource,
        ], 202);
    }
}
