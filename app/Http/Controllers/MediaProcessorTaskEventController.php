<?php

namespace App\Http\Controllers;

use App\Models\MediaProcessorTask;
use App\Services\MediaProcessing\MediaProcessorTaskEventRecorder;
use App\Services\MediaProcessing\RemoteMediaProcessorClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaProcessorTaskEventController extends Controller
{
    public function __invoke(
        Request $request,
        MediaProcessorTask $mediaProcessorTask,
        RemoteMediaProcessorClient $processor,
        MediaProcessorTaskEventRecorder $events,
    ): JsonResponse {
        $processor->verifyIncoming($request);

        $payload = $request->json()->all();
        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid media processor payload.'], 422);
        }

        $events->record($mediaProcessorTask, $payload);

        return response()->json(['ok' => true]);
    }
}
