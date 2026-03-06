<?php

namespace App\Http\Controllers;

use App\Enums\DownloadTransferStatus;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
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
            'referrer_url' => ['nullable', 'string', 'max:4096'],
            'referrer_url_hash_aware' => ['nullable', 'string', 'max:4096'],
            'page_url' => ['nullable', 'string', 'max:4096'],
            'tag_name' => ['nullable', 'string', 'in:img,video,iframe'],
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
        $payload = $this->processReactionItem(
            $validated,
            $validated['type'],
            $fileReactionService,
            $user,
            $extensionChannel,
            $this->downloadRuntimeContext($validated, $request)
        );

        return response()->json([
            ...$payload,
            'reverb' => $this->reverbPayload($extensionChannel),
        ]);
    }

    public function reactBatch(
        Request $request,
        ExtensionApiKeyService $extensionApiKey,
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
            'primary_candidate_id' => ['required', 'string', 'max:128'],
            'items' => ['required', 'array', 'min:2', 'max:300'],
            'items.*.candidate_id' => ['required', 'string', 'max:128'],
            'items.*.url' => ['required', 'string', 'max:4096'],
            'items.*.referrer_url' => ['nullable', 'string', 'max:4096'],
            'items.*.referrer_url_hash_aware' => ['nullable', 'string', 'max:4096'],
            'items.*.page_url' => ['nullable', 'string', 'max:4096'],
            'items.*.tag_name' => ['nullable', 'string', 'in:img,video,iframe'],
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
        $batchItems = [];
        $primaryPayload = null;
        $primaryCandidateId = $validated['primary_candidate_id'];
        $batchDownloadRequested = false;

        foreach ($validated['items'] as $item) {
            $payload = $this->processReactionItem(
                $item,
                $validated['type'],
                $fileReactionService,
                $user,
                $extensionChannel,
                $runtimeContext
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
        ?string $referrerUrl,
        ?string $previewUrl,
        string $extensionChannel,
        int $extensionUserId,
        ?string $pageUrl,
        ?string $tagName,
    ): File {
        $downloadVia = $this->shouldUseYtDlp($url, $pageUrl, $tagName) ? 'yt-dlp' : null;
        $canonicalUrl = $downloadVia === 'yt-dlp' && $pageUrl !== null ? $pageUrl : $url;
        $urlHash = hash('sha256', $canonicalUrl);
        $listingMetadata = array_filter([
            'extension_channel' => $extensionChannel,
            'extension_user_id' => $extensionUserId,
            'page_url' => $pageUrl,
            'tag_name' => $tagName,
            'download_via' => $downloadVia,
        ], static fn ($value) => $value !== null && $value !== '');

        $file = File::query()
            ->where('url_hash', $urlHash)
            ->latest('updated_at')
            ->first();

        if (! $file) {
            return File::query()->create([
                'source' => 'extension',
                'url' => $canonicalUrl,
                'referrer_url' => $referrerUrl,
                'preview_url' => $previewUrl,
                'listing_metadata' => $listingMetadata,
                'filename' => Str::random(40),
                'ext' => FileTypeDetector::extensionFromUrl($canonicalUrl),
            ]);
        }

        $updates = [];
        if ($referrerUrl !== null && $file->referrer_url !== $referrerUrl) {
            $updates['referrer_url'] = $referrerUrl;
        }
        if ($file->preview_url === null && $previewUrl !== null) {
            $updates['preview_url'] = $previewUrl;
        }
        $listingMetadata = is_array($file->listing_metadata) ? $file->listing_metadata : [];
        $listingChanged = false;
        if (($listingMetadata['extension_channel'] ?? null) !== $extensionChannel) {
            $listingMetadata['extension_channel'] = $extensionChannel;
            $listingChanged = true;
        }
        if (($listingMetadata['extension_user_id'] ?? null) !== $extensionUserId) {
            $listingMetadata['extension_user_id'] = $extensionUserId;
            $listingChanged = true;
        }
        if ($pageUrl !== null && ($listingMetadata['page_url'] ?? null) !== $pageUrl) {
            $listingMetadata['page_url'] = $pageUrl;
            $listingChanged = true;
        }
        if ($tagName !== null && ($listingMetadata['tag_name'] ?? null) !== $tagName) {
            $listingMetadata['tag_name'] = $tagName;
            $listingChanged = true;
        }
        if ($downloadVia !== null && ($listingMetadata['download_via'] ?? null) !== $downloadVia) {
            $listingMetadata['download_via'] = $downloadVia;
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
     * @return array<string, mixed>
     */
    private function processReactionItem(
        array $item,
        string $reactionType,
        FileReactionService $fileReactionService,
        User $user,
        string $extensionChannel,
        array $runtimeContext,
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

        $file = $this->findOrCreateFile(
            $url,
            $referrerUrl,
            $previewUrl,
            $extensionChannel,
            (int) $user->id,
            $pageUrl,
            $tagName
        );
        $result = $fileReactionService->set(
            $file,
            $user,
            $reactionType,
            deferHeavySideEffects: true,
            downloadRuntimeContext: $runtimeContext
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
                'requested' => $reactionType !== 'dislike',
                'transfer_id' => $activeTransfer?->id,
                'status' => $activeTransfer?->status,
                'progress_percent' => $activeTransfer?->last_broadcast_percent,
                'downloaded_at' => $file->downloaded_at?->toIso8601String(),
            ],
            'blacklisted_at' => $file->blacklisted_at?->toIso8601String(),
        ];
    }

    private function extensionChannelHash(string $apiKey): string
    {
        return hash('sha256', $apiKey);
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
            'channel' => 'extension-downloads.'.$extensionChannel,
        ];
    }
}
