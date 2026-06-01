<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExtensionSettingsRequest;
use App\Services\Extension\ExtensionRequestAuthenticator;
use App\Services\Extension\ExtensionSettingsService;
use App\Services\ExtensionApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExtensionSettingsController extends Controller
{
    public function __construct(private readonly ExtensionRequestAuthenticator $extensionAuthenticator) {}

    public function show(
        Request $request,
        ExtensionApiKeyService $extensionApiKey,
        ExtensionSettingsService $extensionSettings,
    ): JsonResponse {
        $user = $this->extensionAuthenticator->resolveUser($request, $extensionApiKey);
        if (! $user) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        return response()->json([
            'settings' => $extensionSettings->forUser((int) $user->id),
        ]);
    }

    public function store(
        StoreExtensionSettingsRequest $request,
        ExtensionSettingsService $extensionSettings,
    ): JsonResponse {
        $validated = $request->validated();
        $userId = (int) $request->attributes->get('extension_user_id');

        return response()->json([
            'settings' => $extensionSettings->saveForUser($userId, $validated['settings']),
        ]);
    }
}
