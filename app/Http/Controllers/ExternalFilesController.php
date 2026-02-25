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
    /**
     * Keep hash lookups small enough for consistent indexed query plans.
     */
    private const CHECK_HASH_LOOKUP_CHUNK_SIZE = 50;

    /**
     * @var list<string>
     */
    private const CHECK_QUERY_COLUMNS = [
        'id',
        'url',
        'referrer_url',
        'downloaded',
        'downloaded_at',
        'download_progress',
        'updated_at',
        'blacklisted_at',
    ];

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
            $downloadedFileReset->reset($file, false);
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
        $rawUrls = collect($urls)
            ->map(fn (string $url) => trim($url))
            ->filter(fn (string $url) => $url !== '')
            ->unique()
            ->values()
            ->all();
        $normalizedUrls = collect($rawUrls)
            ->map(fn (string $url) => $this->stripFragment(trim($url)))
            ->filter(fn (string $url) => $url !== '')
            ->unique()
            ->values()
            ->all();
        $normalizedUrlHashes = array_values(array_unique(array_map(
            fn (string $url): string => hash('sha256', $url),
            $normalizedUrls
        )));

        $rawLookupSet = array_fill_keys($rawUrls, true);
        $normalizedLookupSet = array_fill_keys($normalizedUrls, true);
        $allowedLookupSet = $rawLookupSet + $normalizedLookupSet;
        $filesByLookup = [];

        $filesByUrl = $this->lookupFilesByHash('url_hash', $normalizedUrlHashes);
        foreach ($filesByUrl as $file) {
            $fileUrlRaw = is_string($file->url) ? trim($file->url) : '';
            $fileUrl = $this->stripFragment($fileUrlRaw);
            if ($fileUrl !== '' && isset($allowedLookupSet[$fileUrl])) {
                $filesByLookup[$fileUrl][] = $file;
            }
        }

        $referrerCandidates = array_values(array_filter(
            $rawUrls,
            fn (string $url): bool => $this->looksLikePageUrl($url)
        ));
        $normalizedReferrerCandidates = array_values(array_unique(array_map(
            fn (string $url): string => $this->stripFragment($url),
            $referrerCandidates
        )));
        $referrerHashes = array_values(array_unique(array_map(
            fn (string $url): string => hash('sha256', $url),
            array_merge($referrerCandidates, $normalizedReferrerCandidates)
        )));
        $filesByReferrer = $this->lookupFilesByHash('referrer_url_hash', $referrerHashes);
        foreach ($filesByReferrer as $file) {
            $referrerRaw = is_string($file->referrer_url) ? trim($file->referrer_url) : '';
            if ($referrerRaw !== '' && isset($allowedLookupSet[$referrerRaw])) {
                $filesByLookup[$referrerRaw][] = $file;
            }

            $referrerUrl = $this->stripFragment($referrerRaw);
            if ($referrerUrl !== '' && isset($allowedLookupSet[$referrerUrl])) {
                $filesByLookup[$referrerUrl][] = $file;
            }
        }
        $files = $filesByUrl->concat($filesByReferrer)->unique('id')->values();

        foreach ($filesByLookup as $lookup => $candidates) {
            if (! isset($allowedLookupSet[$lookup])) {
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
            $rawLookupUrl = trim($url);
            $lookupUrl = $this->stripFragment($rawLookupUrl);
            /** @var Collection<int, File> $exactCandidates */
            $exactCandidates = collect($filesByLookup[$rawLookupUrl] ?? [])->unique('id')->values();
            /** @var Collection<int, File> $candidates */
            $candidates = $exactCandidates->isNotEmpty()
                ? $exactCandidates
                : collect($filesByLookup[$lookupUrl] ?? [])->unique('id')->values();
            $downloadedCandidate = $candidates->first(fn (File $candidate): bool => (bool) $candidate->downloaded);
            $downloadedAt = optional(
                $candidates
                    ->filter(fn (File $candidate): bool => $candidate->downloaded_at !== null)
                    ->sortByDesc(fn (File $candidate): int => $candidate->downloaded_at?->getTimestamp() ?? 0)
                    ->first()
            )->downloaded_at?->toIso8601String();
            $file = $this->pickBestCheckMatch($candidates, $rawLookupUrl, $lookupUrl, $reactionsByFileId);
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
                'downloaded_at' => $downloadedAt ?: $downloadedCandidate?->updated_at?->toIso8601String(),
                'download_progress' => (int) $candidates->max(fn (File $candidate): int => (int) ($candidate->download_progress ?? 0)),
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
            $downloadedFileReset->reset($file, false);
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
            ->where('url_hash', hash('sha256', $canonicalUrl))
            ->where('url', $canonicalUrl)
            ->first();

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
     * Priority: direct URL match, exact referrer match, normalized referrer match,
     * downloaded, has current-user reaction, newest row.
     */
    private function pickBestCheckMatch(
        Collection $candidates,
        string $rawLookupUrl,
        string $lookupUrl,
        Collection $reactionsByFileId
    ): ?File {
        $best = null;
        $bestScore = [-1, -1, -1, -1, -1, -1];

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof File) {
                continue;
            }

            $candidateReferrer = is_string($candidate->referrer_url) ? trim($candidate->referrer_url) : '';
            $candidateReferrerNormalized = $this->stripFragment($candidateReferrer);
            $score = [
                (int) ($candidate->url === $lookupUrl),
                (int) ($candidateReferrer !== '' && $candidateReferrer === $rawLookupUrl),
                (int) ($candidateReferrerNormalized !== '' && $candidateReferrerNormalized === $lookupUrl),
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

    /**
     * @param  list<string>  $hashes
     * @return Collection<int, File>
     */
    private function lookupFilesByHash(string $hashColumn, array $hashes): Collection
    {
        if ($hashes === []) {
            return collect();
        }

        return collect($hashes)
            ->chunk(self::CHECK_HASH_LOOKUP_CHUNK_SIZE)
            ->flatMap(fn (Collection $hashChunk) => File::query()
                ->whereIn($hashColumn, $hashChunk->all())
                ->get(self::CHECK_QUERY_COLUMNS)
                ->all())
            ->unique('id')
            ->values();
    }
}
