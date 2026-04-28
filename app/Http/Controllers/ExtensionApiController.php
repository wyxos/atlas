<?php

namespace App\Http\Controllers;

use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\Reaction;
use App\Models\Tab;
use App\Models\User;
use App\Services\BrowsePersister;
use App\Services\CivitAiImages;
use App\Services\Extension\ExtensionContainerMetadataService;
use App\Services\Extension\ExtensionDownloadRuntimeContext;
use App\Services\Extension\ExtensionListingMetadataOverridesNormalizer;
use App\Services\Extension\ExtensionMediaMatchService;
use App\Services\Extension\ExtensionReactionProcessor;
use App\Services\ExtensionApiKeyService;
use App\Services\FileReactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExtensionApiController extends Controller
{
    public function ping(Request $request, ExtensionApiKeyService $extensionApiKey): JsonResponse
    {
        $apiKey = trim((string) $request->header('X-Atlas-Api-Key', ''));
        if ($apiKey === '' || ! $this->resolveExtensionUser($request, $extensionApiKey)) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        $extensionChannel = $this->extensionChannelHash($apiKey);

        return response()->json([
            'ok' => true,
            'reverb' => $this->reverbPayload($extensionChannel),
        ]);
    }

    public function broadcastAuth(
        Request $request,
        ExtensionApiKeyService $extensionApiKey,
    ): JsonResponse {
        $apiKey = trim((string) $request->header('X-Atlas-Api-Key', ''));
        $user = $this->resolveExtensionUser($request, $extensionApiKey);
        if ($apiKey === '' || ! $user) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        $channelName = trim((string) $request->input('channel_name', ''));
        $expectedChannel = 'private-extension-downloads.'.$this->extensionChannelHash($apiKey);
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
        $user = $this->resolveExtensionUser($request, $extensionApiKey);
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
        $user = $this->resolveExtensionUser($request, $extensionApiKey);
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
        $user = $this->resolveExtensionUser($request, $extensionApiKey);
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
        FileReactionService $fileReactionService,
        ExtensionDownloadRuntimeContext $downloadRuntimeContext,
        ExtensionListingMetadataOverridesNormalizer $listingMetadataOverridesNormalizer,
        ExtensionReactionProcessor $reactionProcessor,
    ): JsonResponse {
        $user = $this->resolveExtensionUser($request, $extensionApiKey);
        if (! $user) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:love,like,dislike,funny'],
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

        $extensionChannel = $this->extensionChannelHash(trim((string) $request->header('X-Atlas-Api-Key', '')));
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
            $containerMetadataService,
            $user,
            $extensionChannel,
            $downloadRuntimeContext->fromValidated($validated, $request),
            $listingMetadataOverrides,
        );
        $this->attachDerivedContainers([$payload], $listingMetadataOverrides);

        return response()->json([
            ...$payload,
            'reverb' => $this->reverbPayload($extensionChannel),
        ]);
    }

    public function reactBatch(
        Request $request,
        ExtensionApiKeyService $extensionApiKey,
        ExtensionContainerMetadataService $containerMetadataService,
        FileReactionService $fileReactionService,
        ExtensionDownloadRuntimeContext $downloadRuntimeContext,
        ExtensionListingMetadataOverridesNormalizer $listingMetadataOverridesNormalizer,
        ExtensionReactionProcessor $reactionProcessor,
    ): JsonResponse {
        $user = $this->resolveExtensionUser($request, $extensionApiKey);
        if (! $user) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:love,like,dislike,funny'],
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

        $extensionChannel = $this->extensionChannelHash(trim((string) $request->header('X-Atlas-Api-Key', '')));
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

        foreach ($validated['items'] as $item) {
            $payload = $reactionProcessor->process(
                $item,
                $validated['type'],
                $validated['download_behavior'] ?? 'queue',
                $fileReactionService,
                $containerMetadataService,
                $user,
                $extensionChannel,
                $runtimeContext,
                $listingMetadataOverrides
            );

            $candidatePayload = [
                'candidate_id' => $item['candidate_id'],
                ...$payload,
            ];
            $batchItems[] = $candidatePayload;
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

        $this->attachDerivedContainers($batchItems, $listingMetadataOverrides);

        return response()->json([
            ...$primaryPayload,
            'reverb' => $this->reverbPayload($extensionChannel),
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
        $user = $this->resolveExtensionUser($request, $extensionApiKey);
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
        $user = $this->resolveExtensionUser($request, $extensionApiKey);
        if (! $user) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        $validated = $request->validate([
            'model_id' => ['required', 'integer', 'min:1'],
            'model_version_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $modelId = (int) $validated['model_id'];
        $modelVersionId = isset($validated['model_version_id']) ? (int) $validated['model_version_id'] : null;
        $params = array_filter([
            'feed' => 'online',
            'service' => CivitAiImages::key(),
            'page' => 1,
            'limit' => 20,
            'modelId' => $modelId,
            'modelVersionId' => $modelVersionId,
        ], static fn (mixed $value): bool => $value !== null);

        $tab = $this->createExtensionBrowseTab(
            $user,
            $this->buildCivitAiModelBrowseTabLabel($modelId, $modelVersionId),
            $params,
        );

        return response()->json([
            'tab' => [
                'id' => $tab->id,
                'label' => $tab->label,
                'params' => $tab->params ?? [],
            ],
            'browse_url' => url('/browse'),
        ]);
    }

    public function openCivitAiUserBrowseTab(
        Request $request,
        ExtensionApiKeyService $extensionApiKey,
    ): JsonResponse {
        $user = $this->resolveExtensionUser($request, $extensionApiKey);
        if (! $user) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:255'],
        ]);

        $username = trim((string) $validated['username']);
        if ($username === '') {
            throw ValidationException::withMessages([
                'username' => 'The username field is required.',
            ]);
        }

        $tab = $this->createExtensionBrowseTab(
            $user,
            "CivitAI Images: User $username - 1",
            [
                'feed' => 'online',
                'service' => CivitAiImages::key(),
                'page' => 1,
                'limit' => 20,
                'username' => $username,
            ],
        );

        return response()->json([
            'tab' => [
                'id' => $tab->id,
                'label' => $tab->label,
                'params' => $tab->params ?? [],
            ],
            'browse_url' => url('/browse'),
        ]);
    }

    private function resolveExtensionUser(Request $request, ExtensionApiKeyService $extensionApiKey): ?\App\Models\User
    {
        $apiKey = trim((string) $request->header('X-Atlas-Api-Key', ''));
        if ($apiKey === '') {
            return null;
        }

        return $extensionApiKey->resolveUserForApiKey($apiKey);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function createExtensionBrowseTab(User $user, string $label, array $params): Tab
    {
        return DB::transaction(function () use ($user, $label, $params) {
            $userId = (int) $user->id;
            $nextPosition = (Tab::forUser($userId)->max('position') ?? -1) + 1;

            Tab::forUser($userId)->update(['is_active' => false]);

            return Tab::query()->create([
                'user_id' => $userId,
                'label' => $label,
                'custom_label' => null,
                'params' => $params,
                'position' => $nextPosition,
                'is_active' => true,
            ]);
        });
    }

    private function buildCivitAiModelBrowseTabLabel(int $modelId, ?int $modelVersionId): string
    {
        if ($modelVersionId !== null && $modelVersionId > 0) {
            return "CivitAI Images: Model {$modelId} @ {$modelVersionId} - 1";
        }

        return "CivitAI Images: Model {$modelId} - 1";
    }

    private function attachDerivedContainers(array $payloads, array $listingMetadataOverrides): void
    {
        if ($listingMetadataOverrides === []) {
            return;
        }

        $fileIds = [];
        foreach ($payloads as $payload) {
            $fileId = data_get($payload, 'file.id');
            if (is_numeric($fileId)) {
                $fileIds[] = (int) $fileId;
            }
        }

        if ($fileIds === []) {
            return;
        }

        app(BrowsePersister::class)->attachContainersForFiles(
            File::query()
                ->whereIn('id', array_values(array_unique($fileIds)))
                ->get()
        );
    }

    private function extensionChannelHash(string $apiKey): string
    {
        return hash('sha256', $apiKey);
    }

    /**
     * @return array{enabled: bool, key: string, host: string, port: int, scheme: string, channel: string}
     */
    private function reverbPayload(string $extensionChannel): array
    {
        $reverb = config('broadcasting.connections.reverb');
        $key = trim((string) data_get($reverb, 'key', ''));
        $rawHost = trim((string) data_get($reverb, 'options.host', ''));
        $host = '';
        $hostPort = null;
        if ($rawHost !== '') {
            $hostCandidate = str_contains($rawHost, '://') ? $rawHost : 'https://'.$rawHost;
            $parsedHost = parse_url($hostCandidate, PHP_URL_HOST);
            $parsedPort = parse_url($hostCandidate, PHP_URL_PORT);
            $host = is_string($parsedHost) ? trim($parsedHost) : '';
            $hostPort = is_int($parsedPort) ? $parsedPort : null;
        }
        if ($host === '') {
            $appHost = parse_url((string) config('app.url', ''), PHP_URL_HOST);
            $host = is_string($appHost) ? $appHost : '';
        }
        $configuredPort = (int) data_get($reverb, 'options.port', 443);
        $port = $hostPort ?? $configuredPort;
        $scheme = strtolower((string) data_get($reverb, 'options.scheme', 'https'));
        if ($scheme !== 'http' && $scheme !== 'https') {
            $scheme = 'https';
        }

        return [
            'enabled' => $key !== '' && $host !== '',
            'key' => $key,
            'host' => $host,
            'port' => $port > 0 ? $port : 443,
            'scheme' => $scheme,
            'channel' => 'private-extension-downloads.'.$extensionChannel,
        ];
    }
}
