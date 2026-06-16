<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExtensionCompanionCivitaiFeedRequest;
use App\Http\Requests\ExtensionCompanionCivitaiReactionRequest;
use App\Http\Requests\ExtensionCompanionCivitaiStatusRequest;
use App\Services\CivitAiImages;
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

    public function feed(
        ExtensionCompanionCivitaiFeedRequest $request,
        ExtensionApiKeyService $extensionApiKey,
        CivitAiImages $civitAiImages,
        CompanionCivitaiMediaIntegrator $integrator,
    ): JsonResponse {
        $user = $this->extensionAuthenticator->resolveUser($request, $extensionApiKey);
        if (! $user) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        $validated = $request->validated();
        $modelId = (int) $validated['model_id'];
        $modelVersionId = isset($validated['model_version_id']) ? (int) $validated['model_version_id'] : null;
        $modelType = is_string($validated['model_type'] ?? null) ? $validated['model_type'] : null;
        $limit = max(1, min(200, (int) ($validated['limit'] ?? 20)));
        $response = $civitAiImages->fetch([
            'modelId' => $modelId,
            ...(is_int($modelVersionId) && $modelVersionId > 0 ? ['modelVersionId' => $modelVersionId] : []),
            'nsfw' => (bool) ($validated['nsfw'] ?? false),
            'limit' => $limit,
            'page' => trim((string) ($validated['cursor'] ?? '')) ?: '1',
            'sort' => trim((string) ($validated['sort'] ?? 'Newest')) ?: 'Newest',
        ]);
        $items = collect($response['items'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values()
            ->map(fn (array $item, int $index): array => $this->normalizeFeedItem($item, $modelId, $modelVersionId, $modelType, $index))
            ->filter()
            ->values()
            ->all();
        $statuses = $integrator->status($items, $user);
        $statusesByRequestId = collect($statuses)
            ->filter(fn (array $status): bool => is_string($status['request_id'] ?? null))
            ->keyBy('request_id');
        $visibleItems = collect($items)
            ->map(function (array $item) use ($statusesByRequestId): array {
                $status = $statusesByRequestId->get($item['request_id']);

                return [
                    ...$item,
                    'atlasStatus' => $status,
                ];
            })
            ->filter(fn (array $item): bool => $this->shouldShowFeedItem($item['atlasStatus'] ?? null))
            ->values()
            ->all();

        return response()->json([
            'ok' => true,
            'items' => $visibleItems,
            'metadata' => [
                'nextCursor' => $response['metadata']['nextCursor'] ?? null,
            ],
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

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeFeedItem(
        array $item,
        int $modelId,
        ?int $modelVersionId,
        ?string $modelType,
        int $index,
    ): ?array {
        $id = (int) ($item['id'] ?? 0);
        $url = trim((string) ($item['url'] ?? ''));
        if ($id <= 0 || $url === '') {
            return null;
        }

        return [
            ...$item,
            'request_id' => 'civitai:'.$id,
            'id' => $id,
            'url' => $url,
            'type' => $item['type'] ?? 'image',
            'meta' => is_array($item['meta'] ?? null) ? $item['meta'] : [],
            'modelId' => $modelId,
            ...(is_int($modelVersionId) && $modelVersionId > 0 ? ['modelVersionId' => $modelVersionId] : []),
            ...(is_string($modelType) && trim($modelType) !== '' ? ['modelType' => $modelType] : []),
            'resource_containers' => $this->resourceContainersFor($modelId, $modelVersionId, $modelType),
            'request_index' => $index,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resourceContainersFor(int $modelId, ?int $modelVersionId, ?string $modelType): array
    {
        if (! is_int($modelVersionId) || $modelVersionId <= 0) {
            return [];
        }

        return [[
            'type' => $this->resourceContainerType($modelType),
            'modelId' => $modelId,
            'modelVersionId' => $modelVersionId,
            'referrerUrl' => "https://civitai.com/models/{$modelId}?modelVersionId={$modelVersionId}",
        ]];
    }

    private function resourceContainerType(?string $modelType): string
    {
        return in_array(strtolower(trim((string) $modelType)), ['lora', 'lycoris'], true) ? 'LoRA' : 'Checkpoint';
    }

    /**
     * @param  array<string, mixed>|null  $status
     */
    private function shouldShowFeedItem(?array $status): bool
    {
        if (! is_array($status)) {
            return true;
        }

        return ($status['reaction'] ?? null) === null
            && ($status['downloaded'] ?? false) !== true
            && ($status['blacklisted'] ?? false) !== true
            && ($status['auto_blacklisted'] ?? false) !== true
            && ($status['filtered'] ?? false) !== true
            && ($status['ignored'] ?? false) !== true;
    }
}
