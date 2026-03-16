<?php

namespace App\Http\Controllers;

use App\Enums\DownloadTransferStatus;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Services\BrowsePersister;
use App\Services\CivitAiImages;
use App\Services\Extension\ExtensionContainerMetadataService;
use App\Services\Extension\ExtensionMediaMatchService;
use App\Services\ExtensionApiKeyService;
use App\Services\FileReactionService;
use App\Support\FileTypeDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
            $this->normalizeListingMetadataOverrides($validated['listing_metadata_overrides'] ?? [])
        );
        $payload = $this->processReactionItem(
            $validated,
            $validated['type'],
            $validated['download_behavior'] ?? 'queue',
            $fileReactionService,
            $containerMetadataService,
            $user,
            $extensionChannel,
            $this->downloadRuntimeContext($validated, $request),
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
        $runtimeContext = $this->downloadRuntimeContext($validated, $request);
        $firstItem = $validated['items'][0] ?? [];
        $urlDerivedListingMetadataOverrides = $containerMetadataService->metadataOverridesFromCandidateUrls([
            is_array($firstItem) ? ($firstItem['referrer_url_hash_aware'] ?? null) : null,
            is_array($firstItem) ? ($firstItem['referrer_url'] ?? null) : null,
            is_array($firstItem) ? ($firstItem['page_url'] ?? null) : null,
        ], includePostContainer: true);
        $listingMetadataOverrides = $containerMetadataService->mergeListingMetadataOverrides(
            $urlDerivedListingMetadataOverrides,
            $this->normalizeListingMetadataOverrides($validated['listing_metadata_overrides'] ?? [])
        );
        $batchItems = [];
        $primaryPayload = null;
        $primaryCandidateId = $validated['primary_candidate_id'];
        $batchDownloadRequested = false;

        foreach ($validated['items'] as $item) {
            $payload = $this->processReactionItem(
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

    private function resolveExtensionUser(Request $request, ExtensionApiKeyService $extensionApiKey): ?\App\Models\User
    {
        $apiKey = trim((string) $request->header('X-Atlas-Api-Key', ''));
        if ($apiKey === '') {
            return null;
        }

        return $extensionApiKey->resolveUserForApiKey($apiKey);
    }

    private function normalizeUrl(?string $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }

        $withoutFragment = preg_replace('/#.*$/', '', $trimmed);
        $candidate = is_string($withoutFragment) ? trim($withoutFragment) : $trimmed;
        if ($candidate === '') {
            return null;
        }

        $scheme = parse_url($candidate, PHP_URL_SCHEME);
        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            return null;
        }

        return $candidate;
    }

    private function normalizeOptionalUrl(?string $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $trimmed = trim($url);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function findOrCreateFile(
        string $url,
        string $source,
        ?string $referrerUrl,
        ?string $previewUrl,
        string $extensionChannel,
        int $extensionUserId,
        ?string $pageUrl,
        ?string $tagName,
        array $listingMetadataOverrides = [],
    ): File {
        $downloadVia = $this->shouldUseYtDlp($url, $pageUrl, $tagName) ? 'yt-dlp' : null;
        $rawCanonicalUrl = $downloadVia === 'yt-dlp' && $pageUrl !== null ? $pageUrl : $url;
        $identity = $this->resolveExtensionFileIdentity(
            $rawCanonicalUrl,
            $source,
            $referrerUrl,
            $pageUrl,
            $tagName
        );
        $canonicalUrl = $identity['url'];
        $sourceId = $identity['source_id'];
        $urlHash = hash('sha256', $canonicalUrl);
        $listingMetadata = array_filter([
            'extension_channel' => $extensionChannel,
            'extension_user_id' => $extensionUserId,
            'page_url' => $pageUrl,
            'tag_name' => $tagName,
            'download_via' => $downloadVia,
            ...$listingMetadataOverrides,
        ], static fn ($value) => match (true) {
            $value === null => false,
            is_string($value) => trim($value) !== '',
            is_array($value) => $value !== [],
            default => true,
        });

        $file = $this->findExistingFileForExtensionIdentity($urlHash, $canonicalUrl, $source, $sourceId, $referrerUrl);

        if (! $file) {
            return File::query()->create([
                'source' => $source,
                'source_id' => $sourceId,
                'url' => $canonicalUrl,
                'referrer_url' => $referrerUrl,
                'preview_url' => $previewUrl,
                'listing_metadata' => $listingMetadata,
                'filename' => Str::random(40),
                'ext' => FileTypeDetector::extensionFromUrl($canonicalUrl),
            ]);
        }

        $updates = [];
        $currentSource = strtolower(trim((string) $file->source));
        if (($currentSource === '' || $currentSource === 'extension') && $file->source !== $source) {
            $updates['source'] = $source;
        }
        if ($sourceId !== null && trim((string) ($file->source_id ?? '')) === '') {
            $updates['source_id'] = $sourceId;
        }
        $duplicateCanonicalUrlExists = $file->url !== $canonicalUrl
            && File::query()
                ->where('id', '!=', $file->id)
                ->where('url_hash', $urlHash)
                ->exists();

        if ($file->url !== $canonicalUrl && ! $duplicateCanonicalUrlExists) {
            $updates['url'] = $canonicalUrl;
        }
        if ($referrerUrl !== null && $file->referrer_url !== $referrerUrl) {
            $updates['referrer_url'] = $referrerUrl;
        }
        if ($file->preview_url === null && $previewUrl !== null) {
            $updates['preview_url'] = $previewUrl;
        }
        $listingMetadata = is_array($file->listing_metadata) ? $file->listing_metadata : [];
        $listingChanged = false;
        foreach ($listingMetadataOverrides + [
            'extension_channel' => $extensionChannel,
            'extension_user_id' => $extensionUserId,
            'page_url' => $pageUrl,
            'tag_name' => $tagName,
            'download_via' => $downloadVia,
        ] as $key => $value) {
            if ($value === null || (is_string($value) && trim($value) === '') || (is_array($value) && $value === [])) {
                continue;
            }

            if (($listingMetadata[$key] ?? null) === $value) {
                continue;
            }

            $listingMetadata[$key] = $value;
            $listingChanged = true;
        }
        if ($listingChanged) {
            $updates['listing_metadata'] = $listingMetadata;
        }

        if ($updates !== []) {
            $file->update($updates);
        }

        return $file;
    }

    private function resolveExtensionFileIdentity(
        string $url,
        string $source,
        ?string $referrerUrl,
        ?string $pageUrl,
        ?string $tagName,
    ): array {
        if ($source !== CivitAiImages::SOURCE) {
            return [
                'url' => $url,
                'source_id' => null,
            ];
        }

        $sourceId = $this->extractCivitAiImageIdFromCandidateUrls([$referrerUrl, $pageUrl]);
        if ($sourceId === null) {
            return [
                'url' => $url,
                'source_id' => null,
            ];
        }

        return [
            'url' => $this->canonicalizeCivitAiMediaUrl($url, $sourceId, $tagName) ?? $url,
            'source_id' => $sourceId,
        ];
    }

    private function findExistingFileForExtensionIdentity(
        string $urlHash,
        string $canonicalUrl,
        string $source,
        ?string $sourceId,
        ?string $referrerUrl,
    ): ?File {
        $file = File::query()
            ->where('url_hash', $urlHash)
            ->first();
        if ($file) {
            return $file;
        }

        if ($source !== CivitAiImages::SOURCE) {
            return null;
        }

        if ($sourceId !== null) {
            $file = File::query()
                ->where('source', CivitAiImages::SOURCE)
                ->where('source_id', $sourceId)
                ->orderByDesc('downloaded')
                ->latest('updated_at')
                ->first();
            if ($file) {
                return $file;
            }
        }

        if ($referrerUrl === null) {
            return null;
        }

        return File::query()
            ->where('source', CivitAiImages::SOURCE)
            ->where('referrer_url_hash', hash('sha256', $referrerUrl))
            ->orderByDesc('downloaded')
            ->latest('updated_at')
            ->first();
    }

    private function extractCivitAiImageIdFromCandidateUrls(array $candidateUrls): ?string
    {
        foreach ($candidateUrls as $candidateUrl) {
            $imageId = $this->extractCivitAiImageIdFromUrl(is_string($candidateUrl) ? $candidateUrl : null);
            if ($imageId !== null) {
                return $imageId;
            }
        }

        return null;
    }

    private function extractCivitAiImageIdFromUrl(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($host) || ! is_string($path)) {
            return null;
        }

        $normalizedHost = strtolower(trim($host));
        if ($normalizedHost !== 'civitai.com' && $normalizedHost !== 'www.civitai.com') {
            return null;
        }

        if (preg_match('#^/images/(\d+)(?:/|$)#i', $path, $matches) !== 1) {
            return null;
        }

        return $matches[1] ?? null;
    }

    private function canonicalizeCivitAiMediaUrl(string $url, string $imageId, ?string $tagName): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? strtolower($parts['scheme']) : null;
        $host = isset($parts['host']) && is_string($parts['host']) ? strtolower($parts['host']) : null;
        $path = isset($parts['path']) && is_string($parts['path']) ? trim($parts['path'], '/') : null;
        if ($scheme === null || ! in_array($scheme, ['http', 'https'], true) || $host !== 'image.civitai.com' || $path === null || $path === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
        if (count($segments) < 4) {
            return null;
        }

        $token = $segments[0] ?? '';
        $guid = $segments[1] ?? '';
        $filename = end($segments);
        if (! is_string($filename) || $token === '' || $guid === '') {
            return null;
        }

        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension === '') {
            return null;
        }

        $isVideo = in_array($tagName, ['video', 'iframe'], true)
            || in_array($extension, ['mp4', 'm4v', 'mov', 'webm'], true);
        $transform = $isVideo ? 'transcode=true,original=true,quality=90' : 'original=true';
        $canonicalFilename = $isVideo ? "{$imageId}.{$extension}" : "{$guid}.{$extension}";

        return "{$scheme}://{$host}/{$token}/{$guid}/{$transform}/{$canonicalFilename}";
    }

    private function findActiveTransfer(int $fileId): ?DownloadTransfer
    {
        return DownloadTransfer::query()
            ->select(['id', 'status', 'last_broadcast_percent'])
            ->where('file_id', $fileId)
            ->whereIn('status', [
                DownloadTransferStatus::PENDING,
                DownloadTransferStatus::QUEUED,
                DownloadTransferStatus::PREPARING,
                DownloadTransferStatus::DOWNLOADING,
                DownloadTransferStatus::ASSEMBLING,
                DownloadTransferStatus::PREVIEWING,
                DownloadTransferStatus::PAUSED,
            ])
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $runtimeContext
     * @param  array<string, mixed>  $listingMetadataOverrides
     * @return array<string, mixed>
     */
    private function processReactionItem(
        array $item,
        string $reactionType,
        string $downloadBehavior,
        FileReactionService $fileReactionService,
        ExtensionContainerMetadataService $containerMetadataService,
        User $user,
        string $extensionChannel,
        array $runtimeContext,
        array $listingMetadataOverrides = [],
    ): array {
        $url = $this->normalizeUrl($item['url'] ?? null);
        if ($url === null) {
            throw ValidationException::withMessages([
                'url' => 'A valid media URL is required.',
            ]);
        }

        $referrerUrl = $this->normalizeOptionalUrl($item['referrer_url_hash_aware'] ?? null)
            ?? $this->normalizeOptionalUrl($item['referrer_url'] ?? null);
        $previewUrl = $url;
        $pageUrl = $this->normalizeOptionalUrl($item['page_url'] ?? null);
        $tagName = isset($item['tag_name']) && is_string($item['tag_name']) ? $item['tag_name'] : null;
        $source = $containerMetadataService->sourceFromCandidateUrls([$referrerUrl, $pageUrl, $url]) ?? 'extension';

        $file = $this->findOrCreateFile(
            $url,
            $source,
            $referrerUrl,
            $previewUrl,
            $extensionChannel,
            (int) $user->id,
            $pageUrl,
            $tagName,
            $listingMetadataOverrides
        );
        $queueDownload = $this->shouldQueueExtensionDownload($reactionType, $downloadBehavior);
        $forceDownload = $this->shouldForceExtensionDownload($downloadBehavior);
        $result = $fileReactionService->set(
            $file,
            $user,
            $reactionType,
            [
                'deferHeavySideEffects' => true,
                'queueDownload' => $queueDownload,
                'forceDownload' => $forceDownload,
                'downloadRuntimeContext' => $runtimeContext,
            ]
        );
        $activeTransfer = $this->findActiveTransfer($file->id);

        return [
            'file' => [
                'id' => $file->id,
                'url' => $file->url,
                'referrer_url' => $file->referrer_url,
                'preview_url' => $file->preview_url,
            ],
            'reaction' => $result['reaction'],
            'reacted_at' => $result['reacted_at'] ?? null,
            'download' => [
                'requested' => $queueDownload,
                'transfer_id' => $activeTransfer?->id,
                'status' => $activeTransfer?->status,
                'progress_percent' => $activeTransfer?->last_broadcast_percent,
                'downloaded_at' => $file->downloaded_at?->toIso8601String(),
            ],
            'blacklisted_at' => $file->blacklisted_at?->toIso8601String(),
        ];
    }

    private function shouldQueueExtensionDownload(string $reactionType, string $downloadBehavior): bool
    {
        return $reactionType !== 'dislike' && $downloadBehavior !== 'skip';
    }

    private function shouldForceExtensionDownload(string $downloadBehavior): bool
    {
        return $downloadBehavior === 'force';
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
     * @return array<string, mixed>
     */
    private function normalizeListingMetadataOverrides(mixed $overrides): array
    {
        if (! is_array($overrides)) {
            return [];
        }

        $normalized = [];

        $postId = isset($overrides['postId']) ? (int) $overrides['postId'] : null;
        if ($postId !== null && $postId > 0) {
            $normalized['postId'] = $postId;
        }

        $username = isset($overrides['username']) && is_string($overrides['username'])
            ? trim($overrides['username'])
            : null;
        if ($username !== null && $username !== '') {
            $normalized['username'] = $username;
        }

        $resourceContainers = [];
        foreach ($overrides['resource_containers'] ?? [] as $resourceContainer) {
            if (! is_array($resourceContainer)) {
                continue;
            }

            $type = isset($resourceContainer['type']) && is_string($resourceContainer['type'])
                ? trim($resourceContainer['type'])
                : null;
            $modelId = isset($resourceContainer['modelId']) ? (int) $resourceContainer['modelId'] : null;
            $modelVersionId = isset($resourceContainer['modelVersionId']) ? (int) $resourceContainer['modelVersionId'] : null;
            $referrerUrl = isset($resourceContainer['referrerUrl']) && is_string($resourceContainer['referrerUrl'])
                ? $this->normalizeOptionalUrl($resourceContainer['referrerUrl'])
                : null;

            if (! in_array($type, ['Checkpoint', 'LoRA'], true)
                || $modelId === null || $modelId <= 0
                || $modelVersionId === null || $modelVersionId <= 0
                || $referrerUrl === null) {
                continue;
            }

            $resourceContainers[] = [
                'type' => $type,
                'modelId' => $modelId,
                'modelVersionId' => $modelVersionId,
                'referrerUrl' => $referrerUrl,
            ];
        }

        if ($resourceContainers !== []) {
            $normalized['resource_containers'] = array_values($resourceContainers);
        }

        return $normalized;
    }

    private function shouldUseYtDlp(string $url, ?string $pageUrl, ?string $tagName): bool
    {
        if ($tagName !== 'video' && $tagName !== 'iframe') {
            return false;
        }

        $videoPlatformHosts = [
            'x.com',
            'twitter.com',
            'facebook.com',
            'fb.watch',
            'youtube.com',
            'youtu.be',
            'instagram.com',
            'tiktok.com',
            'vimeo.com',
        ];

        $hosts = array_values(array_filter([
            parse_url($url, PHP_URL_HOST),
            parse_url((string) $pageUrl, PHP_URL_HOST),
        ], static fn ($host) => is_string($host) && $host !== ''));

        foreach ($hosts as $host) {
            $normalizedHost = strtolower($host);
            foreach ($videoPlatformHosts as $platformHost) {
                if ($normalizedHost === $platformHost || str_ends_with($normalizedHost, '.'.$platformHost)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{
     *     cookies?: list<array{
     *         name: string,
     *         value: string,
     *         domain: string,
     *         path: string,
     *         secure: bool,
     *         http_only: bool,
     *         host_only: bool,
     *         expires_at: int|null
     *     }>,
     *     user_agent?: string
     * }
     */
    private function downloadRuntimeContext(array $validated, Request $request): array
    {
        $context = [];

        $cookies = $this->normalizeRuntimeCookies($validated['cookies'] ?? null);
        if ($cookies !== []) {
            $context['cookies'] = $cookies;
        }

        $userAgent = trim((string) ($validated['user_agent'] ?? ''));
        if ($userAgent === '') {
            $userAgent = trim((string) $request->userAgent());
        }
        if ($userAgent !== '') {
            $context['user_agent'] = $userAgent;
        }

        return $context;
    }

    /**
     * @return list<array{
     *     name: string,
     *     value: string,
     *     domain: string,
     *     path: string,
     *     secure: bool,
     *     http_only: bool,
     *     host_only: bool,
     *     expires_at: int|null
     * }>
     */
    private function normalizeRuntimeCookies(mixed $cookies): array
    {
        if (! is_array($cookies)) {
            return [];
        }

        $normalized = [];

        foreach ($cookies as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            $domain = ltrim(strtolower(trim((string) ($row['domain'] ?? ''))), '.');
            $path = trim((string) ($row['path'] ?? '/'));
            if ($path === '') {
                $path = '/';
            } elseif (! str_starts_with($path, '/')) {
                $path = '/'.$path;
            }

            if ($name === '' || $domain === '' || preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1) {
                continue;
            }

            $expiresAt = null;
            if (isset($row['expires_at']) && is_numeric($row['expires_at'])) {
                $expiresAt = max(0, (int) $row['expires_at']);
            }

            $normalized[] = [
                'name' => $name,
                'value' => preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '',
                'domain' => $domain,
                'path' => $path,
                'secure' => ($row['secure'] ?? false) === true,
                'http_only' => ($row['http_only'] ?? false) === true,
                'host_only' => ($row['host_only'] ?? false) === true,
                'expires_at' => $expiresAt,
            ];
        }

        return $normalized;
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
