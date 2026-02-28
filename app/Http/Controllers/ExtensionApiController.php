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
        if (! $this->hasValidApiKey($request, $extensionApiKey)) {
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
        if (! $this->hasValidApiKey($request, $extensionApiKey)) {
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

    public function badgeChecks(
        Request $request,
        ExtensionApiKeyService $extensionApiKey,
        ExtensionMediaMatchService $mediaMatchService,
    ): JsonResponse {
        if (! $this->hasValidApiKey($request, $extensionApiKey)) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        $validated = $request->validate([
            'items' => ['required', 'array', 'max:300'],
            'items.*.request_id' => ['required', 'string', 'max:128'],
            'items.*.url' => ['required', 'string', 'max:4096'],
        ]);

        $matches = $mediaMatchService->badgeChecks($validated['items']);

        return response()->json([
            'matches' => $matches,
        ]);
    }

    private function hasValidApiKey(Request $request, ExtensionApiKeyService $extensionApiKey): bool
    {
        $apiKey = trim((string) $request->header('X-Atlas-Api-Key', ''));

        return $apiKey !== '' && $extensionApiKey->matches($apiKey);
    }
}
