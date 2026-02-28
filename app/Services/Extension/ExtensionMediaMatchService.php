<?php

namespace App\Services\Extension;

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Support\Collection;

class ExtensionMediaMatchService
{
    /**
     * @param  array<int, array{id: string, media_url?: string|null, anchor_url?: string|null, page_url?: string|null}>  $items
     * @return array<int, array{id: string, exists: bool, reaction: string|null, reacted_at: string|null, downloaded_at: string|null, blacklisted_at: string|null}>
     */
    public function match(array $items): array
    {
        $normalizedItems = collect($items)->map(function (array $item): array {
            return [
                'id' => (string) ($item['id'] ?? ''),
                'media_url' => $this->normalizeUrl($item['media_url'] ?? null),
                'anchor_url' => $this->normalizeUrl($item['anchor_url'] ?? null),
                'page_url' => $this->normalizeUrl($item['page_url'] ?? null),
            ];
        })->filter(fn (array $item): bool => $item['id'] !== '');

        if ($normalizedItems->isEmpty()) {
            return [];
        }

        $urls = $normalizedItems
            ->flatMap(fn (array $item): array => array_values(array_filter([
                $item['media_url'],
                $item['anchor_url'],
                $item['page_url'],
            ])))
            ->unique()
            ->values();

        if ($urls->isEmpty()) {
            return $normalizedItems->map(fn (array $item): array => $this->emptyMatch($item['id']))->values()->all();
        }

        $urlHashes = $urls->map(fn (string $url): string => hash('sha256', $url))->all();

        /** @var Collection<int, File> $candidateFiles */
        $candidateFiles = File::query()
            ->select(['id', 'url', 'referrer_url', 'preview_url', 'downloaded_at', 'blacklisted_at', 'updated_at'])
            ->where(function ($query) use ($urlHashes, $urls): void {
                $query
                    ->whereIn('url_hash', $urlHashes)
                    ->orWhereIn('referrer_url_hash', $urlHashes)
                    ->orWhereIn('preview_url', $urls->all());
            })
            ->limit(5000)
            ->get();

        if ($candidateFiles->isEmpty()) {
            return $normalizedItems->map(fn (array $item): array => $this->emptyMatch($item['id']))->values()->all();
        }

        $matchedByItemId = $normalizedItems->mapWithKeys(function (array $item) use ($candidateFiles): array {
            return [$item['id'] => $this->pickBestMatch($item, $candidateFiles)];
        });

        $matchedFilesById = $matchedByItemId
            ->filter()
            ->mapWithKeys(fn (File $file): array => [$file->id => $file]);

        $reactionsByFileId = $this->loadReactions($matchedFilesById->keys()->values());

        return $normalizedItems->map(function (array $item) use ($matchedByItemId, $reactionsByFileId): array {
            /** @var File|null $file */
            $file = $matchedByItemId->get($item['id']);
            if (! $file) {
                return $this->emptyMatch($item['id']);
            }

            $reaction = $reactionsByFileId->get($file->id);

            return [
                'id' => $item['id'],
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

    private function scoreMatch(array $item, File $file): int
    {
        $score = 0;

        if ($item['media_url']) {
            if ($file->url === $item['media_url']) {
                $score += 120;
            }
            if ($file->preview_url === $item['media_url']) {
                $score += 110;
            }
            if ($file->referrer_url === $item['media_url']) {
                $score += 40;
            }
        }

        if ($item['anchor_url']) {
            if ($file->referrer_url === $item['anchor_url']) {
                $score += 100;
            }
            if ($file->url === $item['anchor_url']) {
                $score += 80;
            }
            if ($file->preview_url === $item['anchor_url']) {
                $score += 60;
            }
        }

        if ($item['page_url']) {
            if ($file->referrer_url === $item['page_url']) {
                $score += 70;
            }
            if ($file->url === $item['page_url']) {
                $score += 45;
            }
            if ($file->preview_url === $item['page_url']) {
                $score += 30;
            }
        }

        return $score;
    }

    private function pickBestMatch(array $item, Collection $candidateFiles): ?File
    {
        $bestFile = null;
        $bestScore = 0;

        foreach ($candidateFiles as $file) {
            $score = $this->scoreMatch($item, $file);
            if ($score === 0) {
                continue;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestFile = $file;

                continue;
            }

            if ($bestFile && $score === $bestScore && $file->updated_at?->gt($bestFile->updated_at)) {
                $bestFile = $file;
            }
        }

        return $bestFile;
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
