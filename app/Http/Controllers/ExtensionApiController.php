<?php

namespace App\Http\Controllers;

use App\Services\Extension\ExtensionMediaMatchService;
use App\Services\ExtensionApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExtensionApiController extends Controller
{
    public function ping(Request $request, ExtensionApiKeyService $extensionApiKey): JsonResponse
    {
        $apiKey = trim((string) $request->header('X-Atlas-Api-Key', ''));
        if ($apiKey === '' || ! $extensionApiKey->matches($apiKey)) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        return response()->json([
            'ok' => true,
        ]);
    }

    public function matches(
        Request $request,
        ExtensionApiKeyService $extensionApiKey,
        ExtensionMediaMatchService $mediaMatchService,
    ): JsonResponse {
        $apiKey = trim((string) $request->header('X-Atlas-Api-Key', ''));
        if ($apiKey === '' || ! $extensionApiKey->matches($apiKey)) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        $validated = $request->validate([
            'items' => ['required', 'array', 'max:300'],
            'items.*.candidate_id' => ['required', 'string', 'max:128'],
            'items.*.type' => ['required', 'string', 'in:media,referrer'],
            'items.*.url' => ['required', 'string', 'max:4096'],
        ]);

        $matches = $mediaMatchService->match($validated['items']);

        return response()->json([
            'matches' => $matches,
        ]);
    }
}
