<?php

namespace App\Services\Extension;

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Support\Collection;

class ExtensionMediaMatchService
{
    /**
     * @param  array<int, array{request_id: string, url_hash: string}>  $items
     * @return array<int, array{request_id: string, request_index: int, exists: bool, reaction: string|null, reacted_at: string|null, downloaded_at: string|null, blacklisted_at: string|null}>
     */
    public function badgeChecks(array $items): array
    {
        $normalizedItems = collect($items)->values()->map(function (array $item, int $index): array {
            $urlHash = strtolower(trim((string) ($item['url_hash'] ?? '')));

            return [
                'request_id' => (string) ($item['request_id'] ?? ''),
                'request_index' => $index,
                'url_hash' => preg_match('/^[a-f0-9]{64}$/', $urlHash) === 1 ? $urlHash : null,
            ];
        })->filter(fn (array $item): bool => $item['request_id'] !== '' && $item['url_hash'] !== null)->values();

        if ($normalizedItems->isEmpty()) {
            return [];
        }

        $hashes = $normalizedItems->pluck('url_hash')->filter()->unique()->values();
        $filesByHash = $this->filesByUrlHash($hashes);

        $matchedFilesById = $filesByHash
            ->mapWithKeys(fn (File $file): array => [$file->id => $file]);

        $reactionsByFileId = $this->loadReactions($matchedFilesById->keys()->values());

        return $normalizedItems->map(function (array $item) use ($filesByHash, $reactionsByFileId): array {
            /** @var File|null $file */
            $file = $filesByHash->get((string) $item['url_hash']);
            if (! $file) {
                return [
                    'request_id' => (string) $item['request_id'],
                    'request_index' => (int) $item['request_index'],
                    'exists' => false,
                    'reaction' => null,
                    'reacted_at' => null,
                    'downloaded_at' => null,
                    'blacklisted_at' => null,
                ];
            }

            $reaction = $reactionsByFileId->get($file->id);

            return [
                'request_id' => (string) $item['request_id'],
                'request_index' => (int) $item['request_index'],
                'exists' => true,
                'reaction' => $reaction['type'] ?? null,
                'reacted_at' => $reaction['reacted_at'] ?? null,
                'downloaded_at' => $file->downloaded_at?->toIso8601String(),
                'blacklisted_at' => $file->blacklisted_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, string>  $hashes
     * @return Collection<string, File>
     */
    private function filesByUrlHash(Collection $hashes): Collection
    {
        if ($hashes->isEmpty()) {
            return collect();
        }

        return File::query()
            ->select(['id', 'url_hash', 'downloaded_at', 'blacklisted_at', 'updated_at'])
            ->whereIn('url_hash', $hashes->all())
            ->orderByDesc('updated_at')
            ->get()
            ->filter(fn (File $file): bool => is_string($file->url_hash) && $file->url_hash !== '')
            ->unique('url_hash')
            ->keyBy('url_hash');
    }

    /**
     * @param  array<int, array{candidate_id: string, type: string, url: string}>  $items
     * @return array<int, array{id: string, exists: bool, reaction: string|null, reacted_at: string|null, downloaded_at: string|null, blacklisted_at: string|null}>
     */
    public function match(array $items): array
    {
        $normalizedItems = collect($items)->map(function (array $item): array {
            $type = trim((string) ($item['type'] ?? ''));

            return [
                'candidate_id' => (string) ($item['candidate_id'] ?? ''),
                'type' => in_array($type, ['media', 'referrer'], true) ? $type : '',
                'url' => $this->normalizeUrl($item['url'] ?? null),
            ];
        })->filter(fn (array $item): bool => $item['candidate_id'] !== '' && $item['type'] !== '' && $item['url'] !== null);

        if ($normalizedItems->isEmpty()) {
            return [];
        }

        $candidateIds = $normalizedItems->pluck('candidate_id')->unique()->values();
        $mediaUrls = $normalizedItems->where('type', 'media')->pluck('url')->unique()->values();
        $referrerUrls = $normalizedItems->where('type', 'referrer')->pluck('url')->unique()->values();

        $mediaFileByUrl = $this->filesByUrl($mediaUrls);
        $referrerFileByUrl = $this->filesByReferrerUrl($referrerUrls);

        // Priority requested:
        // 1) media object checks files.url
        // 2) referrer object checks files.referrer_url
        $matchedByCandidateId = [];
        foreach ($candidateIds as $candidateId) {
            $candidateRows = $normalizedItems->where('candidate_id', $candidateId);

            $matchedFile = null;
            foreach ($candidateRows->where('type', 'media') as $row) {
                $match = $mediaFileByUrl->get($row['url']);
                if ($match !== null) {
                    $matchedFile = $match;
                    break;
                }
            }

            if ($matchedFile === null) {
                foreach ($candidateRows->where('type', 'referrer') as $row) {
                    $match = $referrerFileByUrl->get($row['url']);
                    if ($match !== null) {
                        $matchedFile = $match;
                        break;
                    }
                }
            }

            $matchedByCandidateId[(string) $candidateId] = $matchedFile;
        }

        $matchedFilesById = collect($matchedByCandidateId)
            ->filter()
            ->mapWithKeys(fn (File $file): array => [$file->id => $file]);

        $reactionsByFileId = $this->loadReactions($matchedFilesById->keys()->values());

        return $candidateIds->map(function (string $candidateId) use ($matchedByCandidateId, $reactionsByFileId): array {
            /** @var File|null $file */
            $file = $matchedByCandidateId[$candidateId] ?? null;
            if (! $file) {
                return $this->emptyMatch($candidateId);
            }

            $reaction = $reactionsByFileId->get($file->id);

            return [
                'id' => $candidateId,
                'exists' => true,
                'reaction' => $reaction['type'] ?? null,
                'reacted_at' => $reaction['reacted_at'] ?? null,
                'downloaded_at' => $file->downloaded_at?->toIso8601String(),
                'blacklisted_at' => $file->blacklisted_at?->toIso8601String(),
            ];
        })->values()->all();
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
        if (! is_string($withoutFragment)) {
            return $trimmed;
        }

        return trim($withoutFragment);
    }

    /**
     * @param  Collection<int, string>  $urls
     * @return Collection<string, File>
     */
    private function filesByUrl(Collection $urls): Collection
    {
        if ($urls->isEmpty()) {
            return collect();
        }

        $hashes = $urls->map(fn (string $url): string => hash('sha256', $url))->all();

        $byUrl = File::query()
            ->select(['id', 'url', 'downloaded_at', 'blacklisted_at', 'updated_at'])
            ->whereIn('url_hash', $hashes)
            ->orderByDesc('updated_at')
            ->get()
            ->filter(fn (File $file): bool => is_string($file->url) && $file->url !== '')
            ->unique('url')
            ->keyBy('url');

        $missingUrls = $urls
            ->filter(fn (string $url): bool => ! $byUrl->has($url))
            ->values();

        if ($missingUrls->isNotEmpty()) {
            // Fallback for legacy rows missing url_hash: exact url lookup for unresolved keys only.
            $fallbackByUrl = File::query()
                ->select(['id', 'url', 'downloaded_at', 'blacklisted_at', 'updated_at'])
                ->whereIn('url', $missingUrls->all())
                ->orderByDesc('updated_at')
                ->get()
                ->filter(fn (File $file): bool => is_string($file->url) && $file->url !== '')
                ->unique('url')
                ->keyBy('url');

            $byUrl = $byUrl->union($fallbackByUrl);
        }

        return $byUrl;
    }

    /**
     * @param  Collection<int, string>  $urls
     * @return Collection<string, File>
     */
    private function filesByReferrerUrl(Collection $urls): Collection
    {
        if ($urls->isEmpty()) {
            return collect();
        }

        $hashes = $urls->map(fn (string $url): string => hash('sha256', $url))->all();

        $byReferrerUrl = File::query()
            ->select(['id', 'referrer_url', 'downloaded_at', 'blacklisted_at', 'updated_at'])
            ->whereIn('referrer_url_hash', $hashes)
            ->orderByDesc('updated_at')
            ->get()
            ->filter(fn (File $file): bool => is_string($file->referrer_url) && $file->referrer_url !== '')
            ->unique('referrer_url')
            ->keyBy('referrer_url');

        $missingUrls = $urls
            ->filter(fn (string $url): bool => ! $byReferrerUrl->has($url))
            ->values();

        if ($missingUrls->isNotEmpty()) {
            // Fallback for legacy rows missing referrer_url_hash: exact referrer lookup for unresolved keys only.
            $fallbackByReferrerUrl = File::query()
                ->select(['id', 'referrer_url', 'downloaded_at', 'blacklisted_at', 'updated_at'])
                ->whereIn('referrer_url', $missingUrls->all())
                ->orderByDesc('updated_at')
                ->get()
                ->filter(fn (File $file): bool => is_string($file->referrer_url) && $file->referrer_url !== '')
                ->unique('referrer_url')
                ->keyBy('referrer_url');

            $byReferrerUrl = $byReferrerUrl->union($fallbackByReferrerUrl);
        }

        return $byReferrerUrl;
    }

    /**
     * @param  Collection<int, int>  $fileIds
     * @return Collection<int, array{type: string, reacted_at: string|null}>
     */
    private function loadReactions(Collection $fileIds): Collection
    {
        if ($fileIds->isEmpty()) {
            return collect();
        }

        $reactionUserId = $this->resolveReactionUserId();

        $query = Reaction::query()
            ->select(['file_id', 'type', 'created_at'])
            ->whereIn('file_id', $fileIds->all())
            ->orderByDesc('created_at');

        if ($reactionUserId !== null) {
            $query->where('user_id', $reactionUserId);
        }

        return $query
            ->get()
            ->unique('file_id')
            ->keyBy('file_id')
            ->map(fn (Reaction $reaction): array => [
                'type' => $reaction->type,
                'reacted_at' => $reaction->created_at?->toIso8601String(),
            ]);
    }

    private function resolveReactionUserId(): ?int
    {
        $configuredUserId = (int) config('downloads.extension_user_id', 0);
        if ($configuredUserId > 0 && User::query()->whereKey($configuredUserId)->exists()) {
            return $configuredUserId;
        }

        $singleUser = User::query()->select('id')->limit(2)->pluck('id');

        return $singleUser->count() === 1 ? (int) $singleUser->first() : null;
    }

    private function emptyMatch(string $id): array
    {
        return [
            'id' => $id,
            'exists' => false,
            'reaction' => null,
            'reacted_at' => null,
            'downloaded_at' => null,
            'blacklisted_at' => null,
        ];
    }
}
