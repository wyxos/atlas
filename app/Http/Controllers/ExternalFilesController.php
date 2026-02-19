<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckExternalFilesRequest;
use App\Http\Requests\DeleteExternalFileDownloadRequest;
use App\Http\Requests\ReactExternalFileRequest;
use App\Http\Requests\StoreExternalFileRequest;
use App\Http\Resources\FileResource;
use App\Models\File;
use App\Models\Reaction;
use App\Services\DownloadedFileResetService;
use App\Services\ExtensionUserResolver;
use App\Services\ExternalFileIngestService;
use App\Services\FileReactionService;
use Illuminate\Http\JsonResponse;

class ExternalFilesController extends Controller
{
    public function store(
        StoreExternalFileRequest $request,
        ExternalFileIngestService $service,
        ExtensionUserResolver $extensionUserResolver,
        FileReactionService $fileReactions,
        DownloadedFileResetService $downloadedFileReset,
    ): JsonResponse {
        $validated = $request->validated();
        $reactionType = $validated['reaction_type'];
        $forceDownload = (bool) ($validated['force_download'] ?? false);

        // Always drive download through the reaction pipeline (no fallback path).
        $result = $service->ingest($validated, false);
        $file = $result['file'];

        if (! $file) {
            return response()->json([
                'message' => 'Unable to store file.',
                'created' => $result['created'],
                'queued' => false,
                'file' => null,
                'reaction' => null,
            ], 422)->withHeaders([
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, X-Atlas-Extension-Token, Authorization',
            ]);
        }

        if ($forceDownload && $reactionType !== 'dislike') {
            $downloadedFileReset->reset($file);
            $file = $file->refresh();
        }

        $user = $extensionUserResolver->resolve();
        $reaction = $fileReactions->set($file, $user, $reactionType)['reaction'];

        return response()->json([
            'message' => 'Reaction updated.',
            'created' => $result['created'],
            'queued' => $reactionType !== 'dislike' && ! $file->downloaded,
            'file' => $file ? new FileResource($file) : null,
            'reaction' => $reaction,
        ], $result['created'] ? 201 : 200)->withHeaders([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Atlas-Extension-Token, Authorization',
        ]);
    }

    public function check(CheckExternalFilesRequest $request): JsonResponse
    {
        $urls = $request->validated()['urls'];
        $normalizedUrls = collect($urls)
            ->map(fn (string $url) => $this->stripFragment(trim($url)))
            ->filter(fn (string $url) => $url !== '')
            ->unique()
            ->values()
            ->all();

        $files = File::query()
            ->whereIn('url', $normalizedUrls)
            ->get(['id', 'url', 'downloaded', 'blacklisted_at']);

        $byUrl = $files->keyBy('url');

        $user = null;
        $reactionsByFileId = collect();

        try {
            $user = app(ExtensionUserResolver::class)->resolve();
        } catch (\Throwable) {
            // Reactions will be null if we can't resolve a user.
        }

        if ($user && $files->isNotEmpty()) {
            $reactionsByFileId = Reaction::query()
                ->where('user_id', $user->id)
                ->whereIn('file_id', $files->pluck('id')->all())
                ->get()
                ->keyBy('file_id');
        }

        $results = array_map(function (string $url) use ($byUrl, $reactionsByFileId): array {
            $lookupUrl = $this->stripFragment(trim($url));
            /** @var File|null $file */
            $file = $byUrl->get($lookupUrl);

            /** @var Reaction|null $reaction */
            $reaction = $file ? $reactionsByFileId->get($file->id) : null;

            return [
                'url' => $url,
                'exists' => $file !== null,
                'downloaded' => $file ? (bool) $file->downloaded : false,
                'blacklisted' => $file ? $file->blacklisted_at !== null : false,
                'file_id' => $file?->id,
                'reaction' => $reaction ? ['type' => $reaction->type] : null,
            ];
        }, $urls);

        return response()->json([
            'results' => $results,
        ])->withHeaders([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Atlas-Extension-Token, Authorization',
        ]);
    }

    public function react(
        ReactExternalFileRequest $request,
        ExternalFileIngestService $service,
        ExtensionUserResolver $extensionUserResolver,
        FileReactionService $fileReactions,
        DownloadedFileResetService $downloadedFileReset,
    ): JsonResponse {
        $validated = $request->validated();
        $forceDownload = (bool) ($validated['force_download'] ?? false);
        $clearDownload = (bool) ($validated['clear_download'] ?? false);
        $blacklist = (bool) ($validated['blacklist'] ?? false);

        // Create/update the file record, but let the reaction pipeline decide whether to dispatch download.
        $result = $service->ingest($validated, false);
        $file = $result['file'];

        if (! $file) {
            return response()->json([
                'message' => 'Unable to store file.',
                'file' => null,
                'reaction' => null,
            ], 422)->withHeaders([
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, X-Atlas-Extension-Token, Authorization',
            ]);
        }

        if ($forceDownload && $validated['type'] !== 'dislike') {
            $downloadedFileReset->reset($file);
            $file = $file->refresh();
        }
        if ($clearDownload) {
            $downloadedFileReset->reset($file);
            $file = $file->refresh();
        }

        $user = $extensionUserResolver->resolve();
        $reaction = $fileReactions->set($file, $user, $validated['type'])['reaction'];

        if ($blacklist && $file->blacklisted_at === null) {
            app(\App\Services\MetricsService::class)->applyBlacklistAdd([$file->id], true);
            $file->forceFill([
                'blacklisted_at' => now(),
                'blacklist_reason' => 'Extension blacklist',
            ])->save();
            $file->searchable();
        }

        return response()->json([
            'message' => 'Reaction updated.',
            'file' => new FileResource($file),
            'reaction' => $reaction,
        ])->withHeaders([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Atlas-Extension-Token, Authorization',
        ]);
    }

    public function deleteDownload(
        DeleteExternalFileDownloadRequest $request,
        DownloadedFileResetService $downloadedFileReset,
    ): JsonResponse {
        $validated = $request->validated();
        $canonicalUrl = $this->resolveCanonicalUrl(
            (string) ($validated['url'] ?? ''),
            (string) ($validated['download_via'] ?? ''),
            (string) ($validated['tag_name'] ?? '')
        );

        $file = File::query()
            ->where('url', $canonicalUrl)
            ->first();
        if (! $file) {
            $file = File::query()->where('url', $canonicalUrl)->first();
        }

        if (! $file) {
            return response()->json([
                'message' => 'File not found.',
                'file' => null,
            ], 404)->withHeaders([
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, X-Atlas-Extension-Token, Authorization',
            ]);
        }

        $downloadedFileReset->reset($file);
        $file = $file->refresh();
        $file->searchable();

        return response()->json([
            'message' => 'Download deleted.',
            'file' => new FileResource($file),
        ])->withHeaders([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Atlas-Extension-Token, Authorization',
        ]);
    }

    private function resolveCanonicalUrl(string $url, string $downloadVia, string $tagName): string
    {
        $url = trim($url);

        $canonicalUrl = $url;
        if ($downloadVia === 'yt-dlp' && in_array($tagName, ['video', 'iframe'], true)) {
            $canonicalUrl = $url !== '' ? $url : $canonicalUrl;
        }

        return $this->stripFragment($canonicalUrl);
    }

    private function stripFragment(string $url): string
    {
        $hashPos = strpos($url, '#');
        if ($hashPos !== false) {
            return substr($url, 0, $hashPos);
        }

        return $url;
    }
}
