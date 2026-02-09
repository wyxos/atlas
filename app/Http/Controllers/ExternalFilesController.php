<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckExternalFilesRequest;
use App\Http\Requests\ReactExternalFileRequest;
use App\Http\Requests\StoreExternalFileRequest;
use App\Http\Resources\FileResource;
use App\Models\File;
use App\Models\Reaction;
use App\Services\ExtensionUserResolver;
use App\Services\ExternalFileIngestService;
use App\Services\FileReactionService;
use App\Services\DownloadedFileResetService;
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

        $files = File::query()
            ->whereIn('referrer_url', $urls)
            ->get(['id', 'referrer_url', 'downloaded']);

        $byUrl = $files->keyBy('referrer_url');

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
            /** @var File|null $file */
            $file = $byUrl->get($url);

            /** @var Reaction|null $reaction */
            $reaction = $file ? $reactionsByFileId->get($file->id) : null;

            return [
                'url' => $url,
                'exists' => $file !== null,
                'downloaded' => $file ? (bool) $file->downloaded : false,
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

        $user = $extensionUserResolver->resolve();
        $reaction = $fileReactions->set($file, $user, $validated['type'])['reaction'];

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
}
