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
use Illuminate\Support\Collection;

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

        $normalizedLookupSet = array_fill_keys($normalizedUrls, true);
        $filesByLookup = [];

        $filesByUrl = collect();
        if ($normalizedUrls !== []) {
            $filesByUrl = File::query()
                ->whereIn('url', $normalizedUrls)
                ->get(['id', 'url', 'referrer_url', 'downloaded', 'blacklisted_at']);

            foreach ($filesByUrl as $file) {
                $fileUrl = $this->stripFragment(is_string($file->url) ? trim($file->url) : '');
                if ($fileUrl !== '' && isset($normalizedLookupSet[$fileUrl])) {
                    $filesByLookup[$fileUrl][] = $file;
                }
            }
        }

        $referrerCandidates = array_values(array_filter(
            $normalizedUrls,
            fn (string $url): bool => $this->looksLikePageUrl($url)
        ));

        $filesByReferrer = collect();
        if ($referrerCandidates !== []) {
            $filesByReferrer = File::query()
                ->whereIn('referrer_url', $referrerCandidates)
                ->get(['id', 'url', 'referrer_url', 'downloaded', 'blacklisted_at']);
            foreach ($filesByReferrer as $file) {
                $referrerUrl = $this->stripFragment(is_string($file->referrer_url) ? trim($file->referrer_url) : '');
                if ($referrerUrl !== '' && isset($normalizedLookupSet[$referrerUrl])) {
                    $filesByLookup[$referrerUrl][] = $file;
                }
            }
        }
        $files = $filesByUrl->concat($filesByReferrer)->unique('id')->values();

        foreach ($filesByLookup as $lookup => $candidates) {
            if (! isset($normalizedLookupSet[$lookup])) {
                unset($filesByLookup[$lookup]);
            }
        }

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

        $results = array_map(function (string $url) use ($filesByLookup, $reactionsByFileId): array {
            $lookupUrl = $this->stripFragment(trim($url));
            /** @var Collection<int, File> $candidates */
            $candidates = collect($filesByLookup[$lookupUrl] ?? []);
            $file = $this->pickBestCheckMatch($candidates, $lookupUrl, $reactionsByFileId);
            $reaction = $file ? $reactionsByFileId->get($file->id) : null;
            if (! $reaction) {
                foreach ($candidates as $candidate) {
                    if (! $candidate instanceof File) {
                        continue;
                    }

                    $candidateReaction = $reactionsByFileId->get($candidate->id);
                    if ($candidateReaction) {
                        $reaction = $candidateReaction;
                        break;
                    }
                }
            }

            return [
                'url' => $url,
                'exists' => $candidates->isNotEmpty(),
                'downloaded' => $candidates->contains(fn (File $candidate): bool => (bool) $candidate->downloaded),
                'blacklisted' => $candidates->contains(fn (File $candidate): bool => $candidate->blacklisted_at !== null),
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
        ExtensionUserResolver $extensionUserResolver,
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

        try {
            $user = $extensionUserResolver->resolve();
        } catch (\Throwable) {
            $user = null;
        }

        if ($user) {
            Reaction::query()
                ->where('file_id', $file->id)
                ->where('user_id', $user->id)
                ->delete();
        }

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

    private function looksLikePageUrl(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            return false;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === '') {
            return true;
        }

        return ! in_array($extension, [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'avif', 'ico',
            'mp4', 'webm', 'mov', 'm4v', 'mkv', 'avi', 'wmv',
            'mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac',
        ], true);
    }

    /**
     * Pick the most relevant file when a lookup URL can match many rows.
     * Priority: direct URL match, downloaded, has current-user reaction, newest row.
     */
    private function pickBestCheckMatch(Collection $candidates, string $lookupUrl, Collection $reactionsByFileId): ?File
    {
        $best = null;
        $bestScore = [-1, -1, -1, -1];

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof File) {
                continue;
            }

            $score = [
                (int) ($candidate->url === $lookupUrl),
                (int) ((bool) $candidate->downloaded),
                (int) $reactionsByFileId->has($candidate->id),
                (int) $candidate->id,
            ];

            if ($this->isLexicographicallyGreater($score, $bestScore)) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private function isLexicographicallyGreater(array $left, array $right): bool
    {
        $count = min(count($left), count($right));
        for ($i = 0; $i < $count; $i++) {
            if ($left[$i] === $right[$i]) {
                continue;
            }

            return $left[$i] > $right[$i];
        }

        return false;
    }
}
