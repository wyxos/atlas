<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExternalFileRequest;
use App\Http\Resources\FileResource;
use App\Services\ExternalFileIngestService;
use Illuminate\Http\JsonResponse;

class ExternalFilesController extends Controller
{
    public function store(StoreExternalFileRequest $request, ExternalFileIngestService $service): JsonResponse
    {
        $result = $service->ingest($request->validated());
        $file = $result['file'];

        return response()->json([
            'message' => $result['queued'] ? 'Download queued.' : 'File stored.',
            'created' => $result['created'],
            'queued' => $result['queued'],
            'file' => $file ? new FileResource($file) : null,
        ], $result['created'] ? 201 : 200);
    }
}
