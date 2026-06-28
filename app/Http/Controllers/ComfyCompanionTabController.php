<?php

namespace App\Http\Controllers;

use App\Http\Requests\ComfyCompanionCivitAiModelBrowseTabRequest;
use App\Models\User;
use App\Services\ComfyCompanion\ComfyCompanionBrowseTabService;
use App\Services\Extension\ExtensionRequestAuthenticator;
use App\Services\ExtensionApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComfyCompanionTabController extends Controller
{
    public function __construct(private readonly ExtensionRequestAuthenticator $extensionAuthenticator) {}

    public function openCivitAiModel(
        ComfyCompanionCivitAiModelBrowseTabRequest $request,
        ExtensionApiKeyService $extensionApiKey,
        ComfyCompanionBrowseTabService $browseTabs,
    ): JsonResponse {
        $user = $this->resolveUser($request, $extensionApiKey);
        if (! $user) {
            return $this->invalidApiKeyResponse();
        }

        return response()->json($browseTabs->openCivitAiModelTab($user, $request->validated()));
    }

    private function resolveUser(Request $request, ExtensionApiKeyService $extensionApiKey): ?User
    {
        return $this->extensionAuthenticator->resolveUser($request, $extensionApiKey);
    }

    private function invalidApiKeyResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Invalid extension API key.',
        ], 401);
    }
}
