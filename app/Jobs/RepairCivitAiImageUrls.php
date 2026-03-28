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

class RepairCivitAiImageUrls implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $afterId = 0,
        public int $chunk = 500,
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
        foreach ($files as $file) {
            $oldUrl = trim((string) $file->url);
            $newUrl = CivitAiMediaUrl::normalizeImageUrl($oldUrl);

            if (! is_string($newUrl) || $newUrl === '' || $newUrl === $oldUrl) {
                continue;
            }

            if ($this->targetUrlOwnedByAnotherFile((int) $file->id, $newUrl)) {
                continue;
            }

            $updates = [
                'url' => $newUrl,
                'url_hash' => hash('sha256', $newUrl),
                'updated_at' => $updatedAt,
            ];

            $listingMetadata = $this->updatedListingMetadata($file->listing_metadata, $oldUrl, $newUrl);
            if ($listingMetadata !== null) {
                $updates['listing_metadata'] = $listingMetadata;
            }

            if (! $this->dryRun) {
                DB::table('files')
                    ->where('id', $file->id)
                    ->update($updates);
            }
        }

        $this->dispatchNextChunk($files, $limit);
    }

    private function filesForChunk(int $limit): Collection
    {
        return File::query()
            ->select(['id', 'url', 'listing_metadata'])
            ->where('id', '>', $this->afterId)
            ->where('source', CivitAiImages::SOURCE)
            ->where('url', 'like', '%/width=%/%')
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

    private function updatedListingMetadata(mixed $listingMetadata, string $oldUrl, string $newUrl): ?string
    {
        if (! is_array($listingMetadata) || ($listingMetadata['url'] ?? null) !== $oldUrl) {
            return null;
        }

        $listingMetadata['url'] = $newUrl;
        $encoded = json_encode($listingMetadata, JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : null;
    }

    private function targetUrlOwnedByAnotherFile(int $fileId, string $url): bool
    {
        return DB::table('files')
            ->where('id', '!=', $fileId)
            ->where('url_hash', hash('sha256', $url))
            ->exists();
    }
}
