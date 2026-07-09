<?php

namespace App\Http\Controllers;

use App\Http\Resources\FileResource;
use App\Jobs\GenerateFilePreviewAssets;
use App\Models\File;
use App\Services\FilePreviewOriginalHealthService;
use App\Services\FilePreviewRepairService;
use App\Services\FileStorageResponseService;
use App\Support\FilePreviewGeneration;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class FilePreviewAssetsController extends Controller
{
    public function __construct(
        private readonly FileStorageResponseService $fileStorageResponses,
        private readonly FilePreviewOriginalHealthService $originalHealth,
        private readonly FilePreviewRepairService $previewRepair,
    ) {}

    public function __invoke(File $file): JsonResponse
    {
        abort_unless(Auth::check(), 403);

        if (! $file->downloaded && $file->imported_at === null) {
            return response()->json([
                'message' => 'Preview assets can only be generated for stored files.',
            ], 422);
        }

        $health = $this->originalHealth->inspect($file);
        if (! $health['previewable']) {
            return response()->json([
                'message' => 'Preview assets can only be generated for stored image or video files.',
            ], 422);
        }

        if (! $health['healthy']) {
            $result = $this->previewRepair->repairUnhealthyOriginal($file, $health, Auth::id());

            return response()->json([
                'queued' => $result['queued'],
                'action' => $result['action'],
                'file' => $this->resourceFor($result['file']),
            ], $result['queued'] ? 202 : 200);
        }

        GenerateFilePreviewAssets::dispatch((int) $file->id, true);

        $file->refresh();
        $resource = $this->resourceFor($file);
        $resource['preview_generation'] = [
            ...((array) (FilePreviewGeneration::state($file) ?? [])),
            'status' => 'queued',
            'can_retry' => false,
            'message' => null,
        ];

        return response()->json([
            'queued' => true,
            'action' => FilePreviewRepairService::ACTION_PREVIEW_QUEUED,
            'file' => $resource,
        ], 202);
    }

    private function resourceFor(File $file): array
    {
        $this->fileStorageResponses->loadViewerRelations($file);
        $this->fileStorageResponses->hydrateDiskMetadata($file);

        return (new FileResource($file))->toArray(request());
    }
}
