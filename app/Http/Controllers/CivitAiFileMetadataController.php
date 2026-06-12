<?php

namespace App\Http\Controllers;

use App\Enums\SourceMetadataRestoreTarget;
use App\Http\Resources\FileResource;
use App\Models\File;
use App\Services\CivitAiMetadataRestoreService;
use App\Services\FileStorageResponseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CivitAiFileMetadataController extends Controller
{
    public function __invoke(
        File $file,
        CivitAiMetadataRestoreService $restoreService,
        FileStorageResponseService $fileStorageResponses,
    ): JsonResponse {
        abort_unless(Auth::check(), 403);

        $result = $restoreService->restore($file, SourceMetadataRestoreTarget::DETAIL);
        $status = (string) ($result['status'] ?? 'invalid_response');
        $supported = ! in_array($status, ['unsupported_source', 'invalid_source_id'], true);
        $restored = $status === 'restored';

        $payload = [
            'message' => $this->messageForStatus($status),
            'supported' => $supported,
            'changed' => $restored,
            ...$result,
        ];

        if ($restored) {
            $file->refresh();
            $fileStorageResponses->loadViewerRelations($file);
            $fileStorageResponses->hydrateDiskMetadata($file);

            $payload['file'] = new FileResource($file);
        }

        return response()->json($payload, $restored ? 200 : 422);
    }

    private function messageForStatus(string $status): string
    {
        return match ($status) {
            'restored' => 'CivitAI metadata restored.',
            'unsupported_source' => 'This action only supports CivitAI files.',
            'invalid_source_id' => 'This CivitAI file is missing a usable image ID.',
            'api_error' => 'CivitAI metadata lookup failed.',
            'not_found' => 'CivitAI did not return this image.',
            'missing_prompt' => 'CivitAI metadata is still missing prompt data.',
            default => 'CivitAI metadata could not be restored.',
        };
    }
}
