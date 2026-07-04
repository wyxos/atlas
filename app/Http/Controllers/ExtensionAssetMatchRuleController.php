<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExtensionAssetMatchRuleRequest;
use App\Jobs\BackfillExtensionAssetMatchIdentities;
use App\Services\Extension\ExtensionAssetMatchIdentityService;
use App\Services\Extension\ExtensionRequestAuthenticator;
use App\Services\ExtensionApiKeyService;
use Illuminate\Http\JsonResponse;

class ExtensionAssetMatchRuleController extends Controller
{
    public function __construct(private readonly ExtensionRequestAuthenticator $extensionAuthenticator) {}

    public function preview(
        ExtensionAssetMatchRuleRequest $request,
        ExtensionApiKeyService $extensionApiKey,
        ExtensionAssetMatchIdentityService $identities,
    ): JsonResponse {
        if (! $this->extensionAuthenticator->resolveUser($request, $extensionApiKey)) {
            return $this->invalidApiKeyResponse();
        }

        $validated = $request->validated();

        return response()->json([
            'preview' => $identities->previewRule($validated['rule']),
        ]);
    }

    public function apply(
        ExtensionAssetMatchRuleRequest $request,
        ExtensionApiKeyService $extensionApiKey,
    ): JsonResponse {
        if (! $this->extensionAuthenticator->resolveUser($request, $extensionApiKey)) {
            return $this->invalidApiKeyResponse();
        }

        $validated = $request->validated();
        $chunk = (int) ($validated['chunk'] ?? 500);

        BackfillExtensionAssetMatchIdentities::dispatch($validated['rule'], 0, $chunk);

        return response()->json([
            'queued' => true,
        ], 202);
    }

    private function invalidApiKeyResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Invalid extension API key.',
        ], 401);
    }
}
