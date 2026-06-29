<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExtensionSettingsStoreRequest;
use App\Services\Extension\ExtensionClientSettingsStore;
use App\Services\Extension\ExtensionRequestAuthenticator;
use App\Services\ExtensionApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExtensionSettingsController extends Controller
{
    public function __construct(
        private readonly ExtensionRequestAuthenticator $extensionAuthenticator,
        private readonly ExtensionClientSettingsStore $settingsStore,
    ) {}

    public function show(Request $request, ExtensionApiKeyService $extensionApiKey): JsonResponse
    {
        $user = $this->extensionAuthenticator->resolveUser($request, $extensionApiKey);
        if (! $user) {
            return $this->invalidApiKeyResponse();
        }

        return response()->json([
            'settings' => $this->settingsStore->get($user),
        ]);
    }

    public function update(
        ExtensionSettingsStoreRequest $request,
        ExtensionApiKeyService $extensionApiKey,
    ): JsonResponse {
        $user = $this->extensionAuthenticator->resolveUser($request, $extensionApiKey);
        if (! $user) {
            return $this->invalidApiKeyResponse();
        }

        $validated = $request->validated();
        $settings = $this->settingsStore->put($user, $validated['settings']);

        return response()->json([
            'settings' => $settings,
        ]);
    }

    private function invalidApiKeyResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Invalid extension API key.',
        ], 401);
    }
}
