<?php

namespace App\Http\Controllers;

use App\Browser;
use App\Http\Requests\ComfyCompanionCivitAiModelBrowseFeedRequest;
use App\Models\User;
use App\Services\CivitAiImages;
use App\Services\Extension\ExtensionRequestAuthenticator;
use App\Services\ExtensionApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComfyCompanionBrowseController extends Controller
{
    public function __construct(private readonly ExtensionRequestAuthenticator $extensionAuthenticator) {}

    public function civitAiModel(
        ComfyCompanionCivitAiModelBrowseFeedRequest $request,
        ExtensionApiKeyService $extensionApiKey,
    ): JsonResponse {
        $user = $this->resolveUser($request, $extensionApiKey);
        if (! $user) {
            return $this->invalidApiKeyResponse();
        }

        $payload = Browser::handle($this->browseParams($request->validated()), $user);
        $error = $payload['error'] ?? null;
        if (is_array($error)) {
            return response()->json([
                'error' => $error,
                'items' => [],
                'message' => $this->browseServiceErrorMessage($error),
                'metadata' => ['nextCursor' => null],
                'ok' => false,
            ], 502);
        }

        return response()->json([
            'items' => array_map(
                fn (array $item): array => $this->feedItem($item),
                array_values(array_filter($payload['items'] ?? [], 'is_array')),
            ),
            'metadata' => [
                'nextCursor' => $payload['filter']['next'] ?? null,
            ],
            'moderation' => $payload['moderation'] ?? [],
            'ok' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function browseParams(array $validated): array
    {
        $cursor = isset($validated['cursor']) ? trim((string) $validated['cursor']) : '';

        return array_filter([
            'feed' => 'online',
            'limit' => (int) ($validated['limit'] ?? 20),
            'modelId' => (int) $validated['model_id'],
            'modelVersionId' => isset($validated['model_version_id']) ? (int) $validated['model_version_id'] : null,
            'nsfw' => array_key_exists('nsfw', $validated) ? (bool) $validated['nsfw'] : null,
            'page' => $cursor !== '' ? $cursor : 1,
            'period' => $validated['period'] ?? 'AllTime',
            'service' => CivitAiImages::key(),
            'sort' => $validated['sort'] ?? 'Newest',
            'type' => $validated['type'] ?? 'all',
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function feedItem(array $item): array
    {
        $sourceId = $this->numericOrString($item['source_id'] ?? null) ?? $item['id'] ?? null;
        $referrerUrl = is_string($item['referrer_url'] ?? null) ? $item['referrer_url'] : null;
        $sourceUrl = is_string($item['url'] ?? null) ? $item['url'] : null;
        $reactionType = data_get($item, 'reaction.type');
        $downloaded = (bool) ($item['downloaded'] ?? false);

        return [
            'atlasStatus' => [
                'auto_blacklisted' => (bool) ($item['auto_blacklisted'] ?? false),
                'blacklisted' => false,
                'blacklisted_at' => null,
                'download' => [
                    'downloaded_at' => null,
                    'progress_percent' => $downloaded ? 100 : 0,
                    'requested' => false,
                    'status' => $downloaded ? 'completed' : null,
                    'transfer_id' => null,
                ],
                'downloaded' => $downloaded,
                'downloaded_at' => null,
                'exists' => true,
                'file_id' => $item['id'] ?? null,
                'filter_reasons' => [],
                'filtered' => false,
                'ignored' => false,
                'reaction' => is_string($reactionType) ? $reactionType : null,
                'reacted_at' => null,
                'referrer_url' => $referrerUrl,
                'request_id' => 'civitai:'.$sourceId,
                'source_url' => $sourceUrl,
            ],
            'media' => [
                'height' => $item['height'] ?? null,
                'id' => $sourceId,
                'referrer_url' => $referrerUrl,
                'type' => ($item['media_kind'] ?? $item['type'] ?? null) === 'video' ? 'video' : 'image',
                'url' => $sourceUrl,
                'width' => $item['width'] ?? null,
            ],
        ];
    }

    private function numericOrString(mixed $value): int|string|null
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1) {
            return (int) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $error
     */
    private function browseServiceErrorMessage(array $error): string
    {
        $message = $error['message'] ?? null;

        return is_string($message) && trim($message) !== '' ? trim($message) : 'Browse service unavailable';
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
