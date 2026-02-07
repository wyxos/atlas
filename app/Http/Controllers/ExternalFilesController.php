<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckExternalFilesRequest;
use App\Http\Requests\StoreExternalFileRequest;
use App\Http\Resources\FileResource;
use App\Models\File;
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
        ], $result['created'] ? 201 : 200)->withHeaders([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Atlas-Extension-Token, Authorization',
        ]);
    }

    public function check(CheckExternalFilesRequest $request): JsonResponse
    {
        $urls = $request->validated()['urls'];

        $files = File::query()
            ->whereIn('referrer_url', $urls)
            ->get(['id', 'referrer_url', 'downloaded']);

        $byUrl = $files->keyBy('referrer_url');

        $results = array_map(function (string $url) use ($byUrl): array {
            $file = $byUrl->get($url);

            return [
                'url' => $url,
                'exists' => $file !== null,
                'downloaded' => $file ? (bool) $file->downloaded : false,
                'file_id' => $file?->id,
            ];
        }, $urls);

        return response()->json([
            'results' => $results,
        ])->withHeaders([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Atlas-Extension-Token, Authorization',
        ]);
    }
}
