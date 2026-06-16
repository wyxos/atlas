<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExtensionCompanionCivitaiReactionRequest;
use App\Http\Requests\ExtensionCompanionCivitaiStatusRequest;
use App\Services\Extension\CompanionCivitaiMediaIntegrator;
use App\Services\Extension\ExtensionApiPayloadSupport;
use App\Services\Extension\ExtensionDownloadRuntimeContext;
use App\Services\Extension\ExtensionRequestAuthenticator;
use App\Services\ExtensionApiKeyService;
use Illuminate\Http\JsonResponse;

class ExtensionCompanionCivitaiController extends Controller
{
    public function __construct(private readonly ExtensionRequestAuthenticator $extensionAuthenticator) {}

    public function status(
        ExtensionCompanionCivitaiStatusRequest $request,
        ExtensionApiKeyService $extensionApiKey,
        CompanionCivitaiMediaIntegrator $integrator,
    ): JsonResponse {
        $user = $this->extensionAuthenticator->resolveUser($request, $extensionApiKey);
        if (! $user) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        $validated = $request->validated();

        return response()->json([
            'ok' => true,
            'items' => $integrator->status($validated['items'], $user),
        ]);
    }

    public function react(
        ExtensionCompanionCivitaiReactionRequest $request,
        ExtensionApiKeyService $extensionApiKey,
        CompanionCivitaiMediaIntegrator $integrator,
        ExtensionDownloadRuntimeContext $downloadRuntimeContext,
    ): JsonResponse {
        $user = $this->extensionAuthenticator->resolveUser($request, $extensionApiKey);
        if (! $user) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        $validated = $request->validated();
        $payload = $integrator->react(
            $validated,
            $user,
            $downloadRuntimeContext->fromValidated($validated, $request),
        );
        $extensionChannel = $this->extensionAuthenticator->resolveChannel($request, $user);

        return response()->json([
            ...$payload,
            'reverb' => app(ExtensionApiPayloadSupport::class)->reverbPayload($extensionChannel),
        ]);
    }
}
