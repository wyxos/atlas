<?php

namespace App\Http\Controllers;

use App\Enums\SourceMetadataRestoreTarget;
use App\Http\Resources\FileResource;
use App\Models\File;
use App\Services\FileStorageResponseService;
use App\Services\SourceMetadataRestoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class FileSourceMetadataController extends Controller
{
    public function __invoke(
        File $file,
        string $target,
        SourceMetadataRestoreService $sourceMetadataRestoreService,
        FileStorageResponseService $fileStorageResponses,
    ): JsonResponse {
        abort_unless(Auth::check(), 403);

        if (! SourceMetadataRestoreTarget::isValid($target)) {
            return $this->responseForResult($file, [
                'file_id' => (int) $file->id,
                'source_id' => is_scalar($file->source_id) ? (string) $file->source_id : null,
                'status' => 'unsupported_target',
                'target' => $target,
            ], $fileStorageResponses);
        }

        return $this->responseForResult(
            $file,
            $sourceMetadataRestoreService->restore($file, $target),
            $fileStorageResponses,
        );
    }

    private function responseForResult(
        File $file,
        array $result,
        FileStorageResponseService $fileStorageResponses,
    ): JsonResponse {
        $status = (string) ($result['status'] ?? 'invalid_response');
        $supported = ! in_array($status, ['unsupported_target', 'unsupported_source', 'unsupported_provider', 'invalid_source_id'], true);
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
            'restored' => 'Source metadata restored.',
            'unsupported_target' => 'Source metadata restore target is not supported.',
            'unsupported_source' => 'Local files do not have provider metadata to refresh.',
            'unsupported_provider' => 'Source metadata refresh is not implemented for this source yet.',
            'invalid_source_id' => 'This source file is missing a usable source ID.',
            'api_error' => 'Source metadata lookup failed.',
            'not_found' => 'The source did not return this file.',
            default => 'Source metadata could not be refreshed.',
        };
    }
}
