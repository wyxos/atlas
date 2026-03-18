<?php

namespace App\Jobs;

use App\Models\File;
use App\Services\CivitAiImages;
use App\Support\CivitAiMediaUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class RepairReactedBlacklistedFiles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const array POSITIVE_REACTION_TYPES = ['love', 'like', 'funny'];

    public function __construct(
        public int $afterId = 0,
        public int $chunk = 100,
        public string $queueName = 'processing',
        public int $remaining = 0,
        public bool $dryRun = false,
    ) {
        $this->afterId = max(0, $this->afterId);
        $this->chunk = max(1, $this->chunk);
        $this->queueName = trim($this->queueName) !== '' ? trim($this->queueName) : 'processing';
        $this->remaining = max(0, $this->remaining);

        $this->onQueue($this->queueName);
    }

    public function handle(): void
    {
        $limit = $this->currentLimit();
        $files = $this->filesForChunk($limit);
        if ($files->isEmpty()) {
            return;
        }

        $updatedAt = now();
        $changedIds = [];

        foreach ($files as $file) {
            $oldUrl = trim((string) $file->url);
            $resolvedUrl = $this->resolvedUrlForFile($file, $oldUrl);
            $canRewriteUrl = $resolvedUrl !== null
                && $resolvedUrl !== ''
                && $resolvedUrl !== $oldUrl
                && ! $this->targetUrlOwnedByAnotherFile((int) $file->id, $resolvedUrl);

            $updates = [
                'blacklisted_at' => null,
                'blacklist_reason' => null,
                'updated_at' => $updatedAt,
            ];

            if ($canRewriteUrl) {
                $updates['url'] = $resolvedUrl;
                $updates['url_hash'] = hash('sha256', $resolvedUrl);

                $listingMetadata = $this->updatedListingMetadata($file->listing_metadata, $oldUrl, $resolvedUrl);
                if ($listingMetadata !== null) {
                    $updates['listing_metadata'] = $listingMetadata;
                }
            }

            if (! $this->dryRun) {
                DB::table('files')
                    ->where('id', $file->id)
                    ->update($updates);

                DownloadFile::dispatch((int) $file->id, false);
            }

            $changedIds[] = (int) $file->id;
        }

        if (! $this->dryRun) {
            $this->syncSearch($changedIds);
        }

        $this->dispatchNextChunk($files, $limit);
    }

    private function filesForChunk(int $limit): Collection
    {
        return File::query()
            ->select(['id', 'source', 'source_id', 'url', 'listing_metadata'])
            ->where('id', '>', $this->afterId)
            ->whereNotNull('blacklisted_at')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('reactions')
                    ->whereColumn('reactions.file_id', 'files.id')
                    ->whereIn('reactions.type', self::POSITIVE_REACTION_TYPES);
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    private function currentLimit(): int
    {
        if ($this->remaining === 0) {
            return $this->chunk;
        }

        return min($this->chunk, $this->remaining);
    }

    private function dispatchNextChunk(Collection $files, int $limit): void
    {
        if ($files->count() !== $limit) {
            return;
        }

        $remaining = $this->remaining === 0
            ? 0
            : max(0, $this->remaining - $files->count());

        if ($this->remaining > 0 && $remaining === 0) {
            return;
        }

        static::dispatch(
            (int) $files->last()->id,
            $this->chunk,
            $this->queueName,
            $remaining,
            $this->dryRun,
        )->onQueue($this->queueName);
    }

    private function resolvedUrlForFile(File $file, string $currentUrl): ?string
    {
        if ($file->source !== CivitAiImages::SOURCE) {
            return null;
        }

        $sourceId = trim((string) ($file->source_id ?? ''));
        if ($sourceId !== '') {
            $liveUrl = $this->fetchLiveCivitAiUrl($sourceId);
            if ($liveUrl !== null) {
                return $liveUrl;
            }
        }

        return CivitAiMediaUrl::normalizeImageUrl($currentUrl);
    }

    private function fetchLiveCivitAiUrl(string $sourceId): ?string
    {
        try {
            $response = Http::acceptJson()
                ->timeout(5)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 Atlas URL Repair',
                ])
                ->retry(2, 200, throw: false)
                ->get('https://civitai.com/api/v1/images', [
                    'imageId' => $sourceId,
                ]);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $url = data_get($response->json(), 'items.0.url');
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        return $url !== '' ? $url : null;
    }

    private function targetUrlOwnedByAnotherFile(int $fileId, string $url): bool
    {
        return DB::table('files')
            ->where('id', '!=', $fileId)
            ->where('url_hash', hash('sha256', $url))
            ->exists();
    }

    private function updatedListingMetadata(mixed $listingMetadata, string $oldUrl, string $newUrl): ?string
    {
        if (! is_array($listingMetadata) || ($listingMetadata['url'] ?? null) !== $oldUrl) {
            return null;
        }

        $listingMetadata['url'] = $newUrl;
        $encoded = json_encode($listingMetadata, JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : null;
    }

    /**
     * @param  array<int>  $fileIds
     */
    private function syncSearch(array $fileIds): void
    {
        $fileIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $fileIds)));
        $fileIds = array_values(array_filter($fileIds, static fn (int $id): bool => $id > 0));

        if ($fileIds === []) {
            return;
        }

        foreach (array_chunk($fileIds, 500) as $chunk) {
            File::query()
                ->whereIn('id', $chunk)
                ->with(['metadata', 'reactions'])
                ->get()
                ->searchable();
        }
    }
}
