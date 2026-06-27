<?php

namespace App\Jobs;

use App\Models\Container;
use App\Models\File;
use App\Services\BrowsePersister;
use App\Services\DeviantArtImages;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BackfillDeviantArtContainers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $afterId = 0,
        public int $chunk = 500,
        public string $queueName = 'processing',
    ) {
        $this->afterId = max(0, $this->afterId);
        $this->chunk = max(1, $this->chunk);
        $this->queueName = trim($this->queueName) !== '' ? trim($this->queueName) : 'processing';

        $this->onQueue($this->queueName);
    }

    public function handle(
        BrowsePersister $browsePersister,
        DeviantArtImages $deviantArtImages,
    ): void {
        $files = $this->filesForChunk();
        if ($files->isEmpty()) {
            return;
        }

        $filesToAttach = new Collection;

        foreach ($files as $file) {
            $listingMetadata = is_array($file->listing_metadata) ? $file->listing_metadata : [];
            $overrides = $deviantArtImages->containerMetadataFromCandidateUrls(
                $this->candidateUrlsForFile($file, $listingMetadata)
            );

            if ($overrides === [] && ! $this->hasDerivedContainerMetadata($listingMetadata)) {
                continue;
            }

            $mergedListingMetadata = $listingMetadata;
            foreach ($overrides as $key => $value) {
                if (($mergedListingMetadata[$key] ?? null) === $value) {
                    continue;
                }

                $mergedListingMetadata[$key] = $value;
            }

            if ($mergedListingMetadata !== $listingMetadata) {
                $file->forceFill([
                    'listing_metadata' => $mergedListingMetadata,
                ])->save();
            } else {
                $file->listing_metadata = $mergedListingMetadata;
            }

            if ($this->hasDerivedContainerMetadata($mergedListingMetadata)) {
                $filesToAttach->push($file);
            }
        }

        if ($filesToAttach->isNotEmpty()) {
            $browsePersister->attachContainersForFiles($filesToAttach);

            foreach ($filesToAttach as $file) {
                $this->detachLegacyPostContainers($file, $deviantArtImages);
            }
        }

        if ($files->count() === $this->chunk) {
            static::dispatch((int) $files->last()->id, $this->chunk, $this->queueName)->onQueue($this->queueName);
        }
    }

    private function filesForChunk(): Collection
    {
        return File::query()
            ->where('id', '>', $this->afterId)
            ->where(function (Builder $query): void {
                $query
                    ->where('source', 'deviantart.com')
                    ->orWhere('referrer_url', 'like', '%deviantart.com%')
                    ->orWhere('listing_metadata->page_url', 'like', '%deviantart.com%')
                    ->orWhere('listing_metadata->post_container_referrer_url', 'like', '%deviantart.com%')
                    ->orWhere('listing_metadata->user_container_referrer_url', 'like', '%deviantart.com%')
                    ->orWhere('listing_metadata->post_container_source', 'deviantart.com')
                    ->orWhere('listing_metadata->user_container_source', 'deviantart.com');
            })
            ->orderBy('id')
            ->limit($this->chunk)
            ->get();
    }

    private function hasDerivedContainerMetadata(array $listingMetadata): bool
    {
        return (
            ($listingMetadata['post_container_source'] ?? null) === 'deviantart.com'
            && is_string($listingMetadata['post_container_source_id'] ?? null)
            && trim((string) $listingMetadata['post_container_source_id']) !== ''
            && is_string($listingMetadata['post_container_referrer_url'] ?? null)
            && trim((string) $listingMetadata['post_container_referrer_url']) !== ''
        ) || (
            ($listingMetadata['user_container_source'] ?? null) === 'deviantart.com'
            && is_string($listingMetadata['user_container_source_id'] ?? null)
            && trim((string) $listingMetadata['user_container_source_id']) !== ''
        );
    }

    private function candidateUrlsForFile(File $file, array $listingMetadata): array
    {
        return [
            $listingMetadata['post_container_referrer_url'] ?? null,
            $file->referrer_url,
            $listingMetadata['page_url'] ?? null,
            $file->source === 'deviantart.com' ? $file->url : null,
            $listingMetadata['user_container_referrer_url'] ?? null,
        ];
    }

    private function detachLegacyPostContainers(File $file, DeviantArtImages $deviantArtImages): void
    {
        $listingMetadata = is_array($file->listing_metadata) ? $file->listing_metadata : [];
        $canonicalSourceId = $listingMetadata['post_container_source_id'] ?? null;
        if (! is_string($canonicalSourceId) || trim($canonicalSourceId) === '') {
            return;
        }

        $legacyContainers = $file->containers()
            ->where('containers.type', 'Post')
            ->where('containers.source', DeviantArtImages::SOURCE)
            ->where('containers.source_id', 'like', 'http%')
            ->get();

        foreach ($legacyContainers as $legacyContainer) {
            if (! $legacyContainer instanceof Container) {
                continue;
            }

            if ($deviantArtImages->postSourceIdFromUrl($legacyContainer->source_id) !== $canonicalSourceId) {
                continue;
            }

            DB::table('container_file')
                ->where('container_id', $legacyContainer->id)
                ->where('file_id', $file->id)
                ->delete();

            if (! DB::table('container_file')->where('container_id', $legacyContainer->id)->exists()) {
                $legacyContainer->delete();
            }
        }
    }
}
