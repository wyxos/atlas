<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExtensionV2AssetStatusRequest;
use App\Http\Requests\ExtensionV2BroadcastAuthRequest;
use App\Http\Requests\ExtensionV2ReactionRequest;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Services\Extension\ExtensionActiveTransferLookup;
use App\Services\Extension\ExtensionApiPayloadSupport;
use App\Services\Extension\ExtensionContainerMetadataService;
use App\Services\Extension\ExtensionDownloadRuntimeContext;
use App\Services\Extension\ExtensionReactionProcessor;
use App\Services\Extension\ExtensionRequestAuthenticator;
use App\Services\ExtensionApiKeyService;
use App\Services\FileBlacklistService;
use App\Services\FileReactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ExtensionV2Controller extends Controller
{
    public function __construct(private readonly ExtensionRequestAuthenticator $extensionAuthenticator) {}

    public function ping(Request $request, ExtensionApiKeyService $extensionApiKey): JsonResponse
    {
        $user = $this->resolveUser($request, $extensionApiKey);
        if (! $user) {
            return $this->invalidApiKeyResponse();
        }

        return response()->json([
            'ok' => true,
            'reverb' => app(ExtensionApiPayloadSupport::class)->reverbPayload(
                $this->extensionAuthenticator->resolveChannel($request, $user)
            ),
        ]);
    }

    public function broadcastAuth(
        ExtensionV2BroadcastAuthRequest $request,
        ExtensionApiKeyService $extensionApiKey,
    ): JsonResponse {
        $user = $this->resolveUser($request, $extensionApiKey);
        if (! $user) {
            return $this->invalidApiKeyResponse();
        }

        $validated = $request->validated();
        $channelName = (string) $validated['channel_name'];
        $expectedChannel = 'private-extension-downloads.'.$this->extensionAuthenticator->resolveChannel($request, $user);
        if (! hash_equals($expectedChannel, $channelName)) {
            return response()->json([
                'message' => 'Invalid Reverb channel authorization request.',
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

        $socketId = (string) $validated['socket_id'];

        return response()->json([
            'auth' => $key.':'.hash_hmac('sha256', $socketId.':'.$channelName, $secret),
        ]);
    }

    public function react(
        ExtensionV2ReactionRequest $request,
        ExtensionApiKeyService $extensionApiKey,
        ExtensionContainerMetadataService $containerMetadataService,
        FileBlacklistService $fileBlacklistService,
        FileReactionService $fileReactionService,
        ExtensionDownloadRuntimeContext $downloadRuntimeContext,
        ExtensionReactionProcessor $reactionProcessor,
    ): JsonResponse {
        $user = $this->resolveUser($request, $extensionApiKey);
        if (! $user) {
            return $this->invalidApiKeyResponse();
        }

        $validated = $request->validated();
        $extensionChannel = $this->extensionAuthenticator->resolveChannel($request, $user);
        $metadata = is_array($validated['metadata'] ?? null) ? $validated['metadata'] : [];
        $item = [
            'page_url' => $validated['referrer_url'] ?? null,
            'referrer_url' => $validated['referrer_url'] ?? null,
            'referrer_url_hash_aware' => $validated['referrer_url'] ?? null,
            'tag_name' => $this->tagNameFromMetadata($metadata),
            'url' => $validated['asset_url'],
        ];
        $payload = $reactionProcessor->process(
            $item,
            $validated['type'],
            $validated['type'] === 'blacklist' ? 'skip' : 'queue',
            $fileReactionService,
            $fileBlacklistService,
            $containerMetadataService,
            $user,
            $extensionChannel,
            $downloadRuntimeContext->fromValidated([], $request),
            $this->listingMetadataFromV2Payload($validated),
        );

        return response()->json([
            'asset_url' => $validated['asset_url'],
            ...$payload,
            'reverb' => app(ExtensionApiPayloadSupport::class)->reverbPayload($extensionChannel),
        ], 201);
    }

    public function assetStatuses(
        ExtensionV2AssetStatusRequest $request,
        ExtensionApiKeyService $extensionApiKey,
        ExtensionActiveTransferLookup $activeTransferLookup,
    ): JsonResponse {
        $user = $this->resolveUser($request, $extensionApiKey);
        if (! $user) {
            return $this->invalidApiKeyResponse();
        }

        $assetUrls = $this->uniqueStrings($request->validated('asset_urls'));
        $filesByAssetUrl = $this->filesByAssetUrl($assetUrls);
        $fileIds = array_values(array_unique(array_filter(
            array_map(static fn (?File $file): int => (int) ($file?->id ?? 0), $filesByAssetUrl),
            static fn (int $fileId): bool => $fileId > 0,
        )));
        $activeTransfers = $activeTransferLookup->byFileId($fileIds);
        $reactions = Reaction::query()
            ->where('user_id', $user->id)
            ->whereIn('file_id', $fileIds)
            ->pluck('type', 'file_id')
            ->all();
        $assets = [];

        foreach ($assetUrls as $assetUrl) {
            $file = $filesByAssetUrl[$assetUrl] ?? null;
            $assets[$assetUrl] = $file
                ? $this->assetStatusPayload($assetUrl, $file, $activeTransfers[(int) $file->id] ?? null, $reactions)
                : null;
        }

        return response()->json([
            'assets' => $assets,
        ]);
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

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function tagNameFromMetadata(array $metadata): ?string
    {
        return match ($metadata['asset_type'] ?? null) {
            'audio' => 'audio',
            'image' => 'img',
            'video' => 'video',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function listingMetadataFromV2Payload(array $validated): array
    {
        $metadata = is_array($validated['metadata'] ?? null) ? $validated['metadata'] : [];

        return array_filter([
            'asset_type' => $metadata['asset_type'] ?? null,
            'page_title' => $metadata['page_title'] ?? null,
            'resolution' => $metadata['resolution'] ?? null,
            'source' => $validated['source'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  list<string>  $assetUrls
     * @return array<string, File|null>
     */
    private function filesByAssetUrl(array $assetUrls): array
    {
        if ($assetUrls === []) {
            return [];
        }

        $urlHashes = array_map(static fn (string $assetUrl): string => hash('sha256', $assetUrl), $assetUrls);
        $files = File::query()
            ->select(['id', 'url', 'url_hash', 'referrer_url', 'preview_url', 'downloaded_at', 'blacklisted_at'])
            ->where(function ($query) use ($assetUrls, $urlHashes): void {
                $query
                    ->whereIn('url_hash', $urlHashes)
                    ->orWhereIn('preview_url', $assetUrls);
            })
            ->get();

        $byUrlHash = $files->keyBy('url_hash');
        $byPreviewUrl = $files
            ->filter(fn (File $file): bool => is_string($file->preview_url) && $file->preview_url !== '')
            ->keyBy('preview_url');
        $matches = [];

        foreach ($assetUrls as $assetUrl) {
            $matches[$assetUrl] = $byUrlHash->get(hash('sha256', $assetUrl))
                ?? $byPreviewUrl->get($assetUrl);
        }

        return $matches;
    }

    /**
     * @param  array<int|string, string>  $reactions
     * @return array<string, mixed>
     */
    private function assetStatusPayload(string $assetUrl, File $file, mixed $transfer, array $reactions): array
    {
        $reaction = $reactions[$file->id] ?? null;
        $hasActiveTransfer = $transfer !== null;

        return [
            'asset_url' => $assetUrl,
            'blacklisted_at' => $file->blacklisted_at?->toIso8601String(),
            'download' => [
                'downloaded_at' => $hasActiveTransfer ? null : $file->downloaded_at?->toIso8601String(),
                'progress_percent' => (int) ($transfer?->last_broadcast_percent ?? ($file->downloaded_at ? 100 : 0)),
                'status' => $transfer?->status ?? ($file->downloaded_at ? 'completed' : null),
                'transfer_id' => $transfer?->id,
            ],
            'file' => [
                'atlas_url' => url("/browse/file/{$file->id}"),
                'id' => $file->id,
                'preview_url' => $file->preview_url,
                'referrer_url' => $file->referrer_url,
                'url' => $file->url,
            ],
            'reaction' => is_string($reaction) ? ['type' => $reaction] : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function uniqueStrings(mixed $values): array
    {
        if (! $values instanceof Collection && ! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), is_array($values) ? $values : $values->all()),
            static fn (string $value): bool => $value !== '',
        )));
    }
}
