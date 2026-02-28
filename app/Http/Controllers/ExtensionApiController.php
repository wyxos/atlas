<?php

namespace App\Http\Controllers;

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
        if (! $this->resolveExtensionUser($request, $extensionApiKey)) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        return response()->json([
            'ok' => true,
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

        $file = $this->findOrCreateFile($url, $referrerUrl, $previewUrl);
        $result = $fileReactionService->set($file, $user, $validated['type'], deferHeavySideEffects: true);

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
                'downloaded_at' => $file->downloaded_at?->toIso8601String(),
            ],
            'blacklisted_at' => $file->blacklisted_at?->toIso8601String(),
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

    private function findOrCreateFile(string $url, ?string $referrerUrl, ?string $previewUrl): File
    {
        $urlHash = hash('sha256', $url);

        $file = File::query()
            ->where('url_hash', $urlHash)
            ->latest('updated_at')
            ->first();

        if (! $file) {
            $file = File::query()
                ->where('url', $url)
                ->latest('updated_at')
                ->first();
        }

        if (! $file) {
            return File::query()->create([
                'source' => 'extension',
                'url' => $url,
                'referrer_url' => $referrerUrl,
                'preview_url' => $previewUrl,
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

        if ($updates !== []) {
            $file->update($updates);
        }

        return $file;
    }
}
