<?php

namespace App\Http\Controllers;

use App\Enums\DownloadTransferStatus;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\Reaction;
use App\Services\Extension\ExtensionMediaMatchService;
use App\Services\ExtensionApiKeyService;
use App\Services\FileReactionService;
use App\Support\FileTypeDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
        ]);

        $url = $this->normalizeUrl($validated['url']);
        if ($url === null) {
            return response()->json([
                'message' => 'A valid media URL is required.',
            ], 422);
        }

        $referrerUrl = $this->normalizeOptionalUrl($validated['referrer_url_hash_aware'] ?? null)
            ?? $this->normalizeOptionalUrl($validated['referrer_url'] ?? null);
        $previewUrl = $url;
        $extensionChannel = $this->extensionChannelHash(trim((string) $request->header('X-Atlas-Api-Key', '')));

        $file = $this->findOrCreateFile($url, $referrerUrl, $previewUrl, $extensionChannel);
        $result = $fileReactionService->set($file, $user, $validated['type'], deferHeavySideEffects: true);
        $activeTransfer = $this->findActiveTransfer($file->id);

        $reaction = Reaction::query()
            ->select(['type', 'created_at'])
            ->where('user_id', $user->id)
            ->where('file_id', $file->id)
            ->first();

        return response()->json([
            'file' => [
                'id' => $file->id,
                'url' => $file->url,
                'referrer_url' => $file->referrer_url,
                'preview_url' => $file->preview_url,
            ],
            'reaction' => $result['reaction'],
            'reacted_at' => $reaction?->created_at?->toIso8601String(),
            'download' => [
                'requested' => $validated['type'] !== 'dislike',
                'transfer_id' => $activeTransfer?->id,
                'status' => $activeTransfer?->status,
                'progress_percent' => $activeTransfer?->last_broadcast_percent,
                'downloaded_at' => $file->downloaded_at?->toIso8601String(),
            ],
            'blacklisted_at' => $file->blacklisted_at?->toIso8601String(),
            'reverb' => $this->reverbPayload($extensionChannel),
        ]);
    }

    public function downloadStatus(
        Request $request,
        ExtensionApiKeyService $extensionApiKey,
    ): JsonResponse {
        if (! $this->resolveExtensionUser($request, $extensionApiKey)) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        $validated = $request->validate([
            'transfer_id' => ['nullable', 'integer', 'min:1', 'required_without:file_id'],
            'file_id' => ['nullable', 'integer', 'min:1', 'required_without:transfer_id'],
        ]);

        $query = DownloadTransfer::query()
            ->with('file:id,downloaded_at,blacklisted_at')
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

        return response()->json([
            'transfer_id' => $transfer->id,
            'file_id' => $transfer->file_id,
            'status' => $transfer->status,
            'progress_percent' => (int) ($transfer->last_broadcast_percent ?? 0),
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

        return is_string($withoutFragment) ? trim($withoutFragment) : $trimmed;
    }

    private function normalizeOptionalUrl(?string $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $trimmed = trim($url);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function findOrCreateFile(string $url, ?string $referrerUrl, ?string $previewUrl, string $extensionChannel): File
    {
        $urlHash = hash('sha256', $url);

        $file = File::query()
            ->where('url_hash', $urlHash)
            ->latest('updated_at')
            ->first();

        if (! $file) {
            return File::query()->create([
                'source' => 'extension',
                'url' => $url,
                'referrer_url' => $referrerUrl,
                'preview_url' => $previewUrl,
                'listing_metadata' => [
                    'extension_channel' => $extensionChannel,
                ],
                'filename' => Str::random(40),
                'ext' => FileTypeDetector::extensionFromUrl($url),
            ]);
        }

        $updates = [];
        if ($file->referrer_url === null && $referrerUrl !== null) {
            $updates['referrer_url'] = $referrerUrl;
        }
        if ($file->preview_url === null && $previewUrl !== null) {
            $updates['preview_url'] = $previewUrl;
        }
        $listingMetadata = is_array($file->listing_metadata) ? $file->listing_metadata : [];
        if (($listingMetadata['extension_channel'] ?? null) !== $extensionChannel) {
            $listingMetadata['extension_channel'] = $extensionChannel;
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
        $host = trim((string) data_get($reverb, 'options.host', ''));
        if ($host === '') {
            $appHost = parse_url((string) config('app.url', ''), PHP_URL_HOST);
            $host = is_string($appHost) ? $appHost : '';
        }
        $port = (int) data_get($reverb, 'options.port', 443);
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
