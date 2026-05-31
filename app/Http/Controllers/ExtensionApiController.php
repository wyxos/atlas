<?php

namespace App\Http\Controllers;

use App\Models\DownloadTransfer;
use App\Models\Reaction;
use App\Services\Extension\ExtensionApiPayloadSupport;
use App\Services\Extension\ExtensionCivitAiBrowseTabService;
use App\Services\Extension\ExtensionContainerMetadataService;
use App\Services\Extension\ExtensionDeviantArtBrowseTabService;
use App\Services\Extension\ExtensionDownloadRuntimeContext;
use App\Services\Extension\ExtensionListingMetadataOverridesNormalizer;
use App\Services\Extension\ExtensionMediaMatchService;
use App\Services\Extension\ExtensionReactionProcessor;
use App\Services\Extension\ExtensionRequestAuthenticator;
use App\Services\ExtensionApiKeyService;
use App\Services\FileBlacklistService;
use App\Services\FileReactionService;
use App\Services\Library\LibraryIndexSyncDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ExtensionApiController extends Controller
{
    public function __construct(private readonly ExtensionRequestAuthenticator $extensionAuthenticator) {}

    public function ping(Request $request, ExtensionApiKeyService $extensionApiKey): JsonResponse
    {
        $user = $this->extensionAuthenticator->resolveUser($request, $extensionApiKey);
        if (! $user) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        $extensionChannel = $this->extensionAuthenticator->resolveChannel($request, $user);

        return response()->json([
            'ok' => true,
            'reverb' => app(ExtensionApiPayloadSupport::class)->reverbPayload($extensionChannel),
        ]);
    }

    public function broadcastAuth(
        Request $request,
        ExtensionApiKeyService $extensionApiKey,
    ): JsonResponse {
        $user = $this->extensionAuthenticator->resolveUser($request, $extensionApiKey);
        if (! $user) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        $channelName = trim((string) $request->input('channel_name', ''));
        $expectedChannel = 'private-extension-downloads.'.$this->extensionAuthenticator->resolveChannel($request, $user);
        if ($channelName === '' || ! hash_equals($expectedChannel, $channelName)) {
            return response()->json([
                'message' => 'Invalid Reverb channel authorization request.',
            ], 403);
        }

        $socketId = trim((string) $request->input('socket_id', ''));
        if (preg_match('/^\d+\.\d+$/', $socketId) !== 1) {
            return response()->json([
                'message' => 'Invalid Reverb socket id.',
            ], 403);
        }

        $reverb = config('broadcasting.connections.reverb');
        $key = trim((string) data_get($reverb, 'key', ''));
        $secret = trim((string) data_get($reverb, 'secret', ''));
        if ($key === '' || $secret === '') {
            return response()->json([
                'message' => 'Reverb is not configured.',
            ], 503);
        }

        return response()->json([
            'auth' => $key.':'.hash_hmac('sha256', $socketId.':'.$channelName, $secret),
        ]);
    }

    public function matches(
        Request $request,
        ExtensionApiKeyService $extensionApiKey,
        ExtensionMediaMatchService $mediaMatchService,
    ): JsonResponse {
        $user = $this->extensionAuthenticator->resolveUser($request, $extensionApiKey);
        if (! $user) {
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

        $matches = $mediaMatchService->match($validated['items'], (int) $user->id);

        return response()->json([
            'matches' => $matches,
        ]);
    }

    public function badgeChecks(
        Request $request,
        ExtensionApiKeyService $extensionApiKey,
        ExtensionMediaMatchService $mediaMatchService,
    ): JsonResponse {
        $user = $this->extensionAuthenticator->resolveUser($request, $extensionApiKey);
        if (! $user) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        $validated = $request->validate([
            'items' => ['required', 'array', 'max:300'],
            'items.*.request_id' => ['required', 'string', 'max:128'],
            'items.*.url_hash' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
        ]);

        $matches = $mediaMatchService->badgeChecks($validated['items'], (int) $user->id);

        return response()->json([
            'matches' => $matches,
        ]);
    }

    public function referrerChecks(
        Request $request,
        ExtensionApiKeyService $extensionApiKey,
        ExtensionMediaMatchService $mediaMatchService,
    ): JsonResponse {
        $user = $this->extensionAuthenticator->resolveUser($request, $extensionApiKey);
        if (! $user) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        $validated = $request->validate([
            'items' => ['required', 'array', 'max:300'],
            'items.*.request_id' => ['required', 'string', 'max:128'],
            'items.*.referrer_hash' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
        ]);

        $matches = $mediaMatchService->referrerChecks($validated['items'], (int) $user->id);

        return response()->json([
            'matches' => $matches,
        ]);
    }

    public function react(
        Request $request,
        ExtensionApiKeyService $extensionApiKey,
        ExtensionContainerMetadataService $containerMetadataService,
        FileBlacklistService $fileBlacklistService,
        FileReactionService $fileReactionService,
        ExtensionDownloadRuntimeContext $downloadRuntimeContext,
        ExtensionListingMetadataOverridesNormalizer $listingMetadataOverridesNormalizer,
        ExtensionReactionProcessor $reactionProcessor,
    ): JsonResponse {
        $user = $this->extensionAuthenticator->resolveUser($request, $extensionApiKey);
        if (! $user) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:love,like,funny,blacklist'],
            'url' => ['required', 'string', 'max:4096'],
            'download_behavior' => ['nullable', 'string', 'in:queue,skip,force'],
            'referrer_url' => ['nullable', 'string', 'max:4096'],
            'referrer_url_hash_aware' => ['nullable', 'string', 'max:4096'],
            'page_url' => ['nullable', 'string', 'max:4096'],
            'tag_name' => ['nullable', 'string', 'in:img,video,iframe'],
            'listing_metadata_overrides' => ['nullable', 'array'],
            'listing_metadata_overrides.postId' => ['nullable', 'integer', 'min:1'],
            'listing_metadata_overrides.username' => ['nullable', 'string', 'max:255'],
            'listing_metadata_overrides.resource_containers' => ['nullable', 'array', 'max:100'],
            'listing_metadata_overrides.resource_containers.*.type' => ['required', 'string', 'in:Checkpoint,LoRA'],
            'listing_metadata_overrides.resource_containers.*.modelId' => ['required', 'integer', 'min:1'],
            'listing_metadata_overrides.resource_containers.*.modelVersionId' => ['required', 'integer', 'min:1'],
            'listing_metadata_overrides.resource_containers.*.referrerUrl' => ['required', 'string', 'max:4096'],
            'cookies' => ['nullable', 'array', 'max:300'],
            'cookies.*.name' => ['required', 'string', 'max:255'],
            'cookies.*.value' => ['required', 'string', 'max:4096'],
            'cookies.*.domain' => ['required', 'string', 'max:255'],
            'cookies.*.path' => ['required', 'string', 'max:2048'],
            'cookies.*.secure' => ['nullable', 'boolean'],
            'cookies.*.http_only' => ['nullable', 'boolean'],
            'cookies.*.host_only' => ['nullable', 'boolean'],
            'cookies.*.expires_at' => ['nullable', 'integer', 'min:0'],
            'user_agent' => ['nullable', 'string', 'max:1000'],
        ]);

        $extensionChannel = $this->extensionAuthenticator->resolveChannel($request, $user);
        $urlDerivedListingMetadataOverrides = $containerMetadataService->metadataOverridesFromCandidateUrls([
            $validated['referrer_url_hash_aware'] ?? null,
            $validated['referrer_url'] ?? null,
            $validated['page_url'] ?? null,
        ], includePostContainer: false);
        $listingMetadataOverrides = $containerMetadataService->mergeListingMetadataOverrides(
            $urlDerivedListingMetadataOverrides,
            $listingMetadataOverridesNormalizer->normalize($validated['listing_metadata_overrides'] ?? [])
        );
        $payload = $reactionProcessor->process(
            $validated,
            $validated['type'],
            $validated['download_behavior'] ?? 'queue',
            $fileReactionService,
            $fileBlacklistService,
            $containerMetadataService,
            $user,
            $extensionChannel,
            $downloadRuntimeContext->fromValidated($validated, $request),
            $listingMetadataOverrides,
        );
        app(ExtensionApiPayloadSupport::class)->attachDerivedContainers([$payload], $listingMetadataOverrides);

        return response()->json([
            ...$payload,
            'reverb' => app(ExtensionApiPayloadSupport::class)->reverbPayload($extensionChannel),
        ]);
    }

    public function reactBatch(
        Request $request,
        ExtensionApiKeyService $extensionApiKey,
        ExtensionContainerMetadataService $containerMetadataService,
        FileBlacklistService $fileBlacklistService,
        FileReactionService $fileReactionService,
        ExtensionDownloadRuntimeContext $downloadRuntimeContext,
        ExtensionListingMetadataOverridesNormalizer $listingMetadataOverridesNormalizer,
        ExtensionReactionProcessor $reactionProcessor,
        LibraryIndexSyncDispatcher $libraryIndexSyncDispatcher,
    ): JsonResponse {
        $user = $this->extensionAuthenticator->resolveUser($request, $extensionApiKey);
        if (! $user) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:love,like,funny,blacklist'],
            'download_behavior' => ['nullable', 'string', 'in:queue,skip,force'],
            'primary_candidate_id' => ['required', 'string', 'max:128'],
            'items' => ['required', 'array', 'min:2', 'max:300'],
            'items.*.candidate_id' => ['required', 'string', 'max:128'],
            'items.*.url' => ['required', 'string', 'max:4096'],
            'items.*.referrer_url' => ['nullable', 'string', 'max:4096'],
            'items.*.referrer_url_hash_aware' => ['nullable', 'string', 'max:4096'],
            'items.*.page_url' => ['nullable', 'string', 'max:4096'],
            'items.*.tag_name' => ['nullable', 'string', 'in:img,video,iframe'],
            'listing_metadata_overrides' => ['nullable', 'array'],
            'listing_metadata_overrides.postId' => ['nullable', 'integer', 'min:1'],
            'listing_metadata_overrides.username' => ['nullable', 'string', 'max:255'],
            'listing_metadata_overrides.resource_containers' => ['nullable', 'array', 'max:100'],
            'listing_metadata_overrides.resource_containers.*.type' => ['required', 'string', 'in:Checkpoint,LoRA'],
            'listing_metadata_overrides.resource_containers.*.modelId' => ['required', 'integer', 'min:1'],
            'listing_metadata_overrides.resource_containers.*.modelVersionId' => ['required', 'integer', 'min:1'],
            'listing_metadata_overrides.resource_containers.*.referrerUrl' => ['required', 'string', 'max:4096'],
            'cookies' => ['nullable', 'array', 'max:300'],
            'cookies.*.name' => ['required', 'string', 'max:255'],
            'cookies.*.value' => ['required', 'string', 'max:4096'],
            'cookies.*.domain' => ['required', 'string', 'max:255'],
            'cookies.*.path' => ['required', 'string', 'max:2048'],
            'cookies.*.secure' => ['nullable', 'boolean'],
            'cookies.*.http_only' => ['nullable', 'boolean'],
            'cookies.*.host_only' => ['nullable', 'boolean'],
            'cookies.*.expires_at' => ['nullable', 'integer', 'min:0'],
            'user_agent' => ['nullable', 'string', 'max:1000'],
        ]);

        $extensionChannel = $this->extensionAuthenticator->resolveChannel($request, $user);
        $runtimeContext = $downloadRuntimeContext->fromValidated($validated, $request);
        $firstItem = $validated['items'][0] ?? [];
        $urlDerivedListingMetadataOverrides = $containerMetadataService->metadataOverridesFromCandidateUrls([
            is_array($firstItem) ? ($firstItem['referrer_url_hash_aware'] ?? null) : null,
            is_array($firstItem) ? ($firstItem['referrer_url'] ?? null) : null,
            is_array($firstItem) ? ($firstItem['page_url'] ?? null) : null,
        ], includePostContainer: true);
        $listingMetadataOverrides = $containerMetadataService->mergeListingMetadataOverrides(
            $urlDerivedListingMetadataOverrides,
            $listingMetadataOverridesNormalizer->normalize($validated['listing_metadata_overrides'] ?? [])
        );
        $batchItems = [];
        $primaryPayload = null;
        $primaryCandidateId = $validated['primary_candidate_id'];
        $batchDownloadRequested = false;
        $fileIds = [];

        foreach ($validated['items'] as $item) {
            $payload = $reactionProcessor->process(
                $item,
                $validated['type'],
                $validated['download_behavior'] ?? 'queue',
                $fileReactionService,
                $fileBlacklistService,
                $containerMetadataService,
                $user,
                $extensionChannel,
                $runtimeContext,
                $listingMetadataOverrides,
                [
                    'loadActiveTransfer' => false,
                    'queueLibrarySync' => false,
                ],
            );

            $candidatePayload = [
                'candidate_id' => $item['candidate_id'],
                ...$payload,
            ];
            $batchItems[] = $candidatePayload;
            $fileId = data_get($payload, 'file.id');
            if (is_numeric($fileId)) {
                $fileIds[] = (int) $fileId;
            }
            if (data_get($payload, 'download.requested') === true) {
                $batchDownloadRequested = true;
            }

            if ($item['candidate_id'] === $primaryCandidateId) {
                $primaryPayload = $payload;
            }
        }

        if ($primaryPayload === null) {
            throw ValidationException::withMessages([
                'primary_candidate_id' => 'The selected primary candidate was not found in the submitted items.',
            ]);
        }

        app(ExtensionApiPayloadSupport::class)->attachActiveTransfers(
            $batchItems,
            $reactionProcessor->activeTransfersByFileId($fileIds),
        );
        foreach ($batchItems as $batchItem) {
            if ($batchItem['candidate_id'] === $primaryCandidateId) {
                $primaryPayload = $batchItem;
                unset($primaryPayload['candidate_id']);
                break;
            }
        }

        app(ExtensionApiPayloadSupport::class)->attachDerivedContainers($batchItems, $listingMetadataOverrides);
        $libraryIndexSyncDispatcher->filesAndReactions($fileIds);

        return response()->json([
            ...$primaryPayload,
            'reverb' => app(ExtensionApiPayloadSupport::class)->reverbPayload($extensionChannel),
            'batch' => [
                'count' => count($batchItems),
                'primary_candidate_id' => $primaryCandidateId,
                'download_requested' => $batchDownloadRequested,
                'items' => $batchItems,
            ],
        ]);
    }

    public function downloadStatus(
        Request $request,
        ExtensionApiKeyService $extensionApiKey,
    ): JsonResponse {
        $user = $this->extensionAuthenticator->resolveUser($request, $extensionApiKey);
        if (! $user) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        $validated = $request->validate([
            'transfer_id' => ['nullable', 'integer', 'min:1', 'required_without:file_id'],
            'file_id' => ['nullable', 'integer', 'min:1', 'required_without:transfer_id'],
        ]);

        $query = DownloadTransfer::query()
            ->with('file:id,referrer_url,downloaded_at,blacklisted_at')
            ->select(['id', 'file_id', 'status', 'last_broadcast_percent']);

        if (isset($validated['transfer_id'])) {
            $query->whereKey($validated['transfer_id']);
        } else {
            $query->where('file_id', $validated['file_id'])->latest('id');
        }

        $transfer = $query->first();

        if (! $transfer) {
            return response()->json([
                'message' => 'Transfer not found.',
            ], 404);
        }

        $reactionType = null;
        if ($transfer->file_id !== null) {
            $reactionType = Reaction::query()
                ->where('user_id', $user->id)
                ->where('file_id', $transfer->file_id)
                ->value('type');
        }

        return response()->json([
            'transfer_id' => $transfer->id,
            'file_id' => $transfer->file_id,
            'status' => $transfer->status,
            'progress_percent' => (int) ($transfer->last_broadcast_percent ?? 0),
            'reaction' => is_string($reactionType) ? $reactionType : null,
            'referrer_url' => $transfer->file?->referrer_url,
            'downloaded_at' => $transfer->file?->downloaded_at?->toIso8601String(),
            'blacklisted_at' => $transfer->file?->blacklisted_at?->toIso8601String(),
        ]);
    }

    public function openCivitAiModelBrowseTab(
        Request $request,
        ExtensionApiKeyService $extensionApiKey,
    ): JsonResponse {
        $user = $this->extensionAuthenticator->resolveUser($request, $extensionApiKey);
        if (! $user) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        $validated = $request->validate([
            'model_id' => ['required', 'integer', 'min:1'],
            'model_version_id' => ['nullable', 'integer', 'min:1'],
            'nsfw' => ['sometimes', 'boolean'],
        ]);

        return response()->json(
            app(ExtensionCivitAiBrowseTabService::class)->openModelTab($user, $validated)
        );
    }

    public function openCivitAiUserBrowseTab(
        Request $request,
        ExtensionApiKeyService $extensionApiKey,
    ): JsonResponse {
        $user = $this->extensionAuthenticator->resolveUser($request, $extensionApiKey);
        if (! $user) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'nsfw' => ['sometimes', 'boolean'],
        ]);

        return response()->json(
            app(ExtensionCivitAiBrowseTabService::class)->openUserTab($user, $validated)
        );
    }

    public function openDeviantArtUserBrowseTab(
        Request $request,
        ExtensionApiKeyService $extensionApiKey,
    ): JsonResponse {
        $user = $this->extensionAuthenticator->resolveUser($request, $extensionApiKey);
        if (! $user) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:255'],
        ]);

        return response()->json(
            app(ExtensionDeviantArtBrowseTabService::class)->openUserTab($user, $validated)
        );
    }
}
