<?php

namespace App\Jobs;

use App\Models\File;
use App\Services\BrowsePersister;
use App\Services\Extension\ExtensionContainerMetadataService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

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
        ExtensionContainerMetadataService $containerMetadataService,
    ): void {
        $files = $this->filesForChunk();
        if ($files->isEmpty()) {
            return;
        }

        $postEligibleUrls = $this->postEligibleUrls($files, $containerMetadataService);
        $filesToAttach = new Collection;

        foreach ($files as $file) {
            $listingMetadata = is_array($file->listing_metadata) ? $file->listing_metadata : [];
            $postContainerUrl = $containerMetadataService->firstSupportedContainerUrl(
                $this->postCandidateUrlsForFile($file, $listingMetadata)
            );

            $overrides = $containerMetadataService->metadataOverridesFromCandidateUrls(
                $this->candidateUrlsForFile($file, $listingMetadata),
                includePostContainer: $this->shouldIncludePostContainer($listingMetadata, $postContainerUrl, $postEligibleUrls),
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

    private function postEligibleUrls(
        Collection $files,
        ExtensionContainerMetadataService $containerMetadataService,
    ): array {
        $counts = [];

        foreach ($files as $file) {
            $listingMetadata = is_array($file->listing_metadata) ? $file->listing_metadata : [];
            $postContainerUrl = $containerMetadataService->firstSupportedContainerUrl(
                $this->postCandidateUrlsForFile($file, $listingMetadata)
            );

            if ($postContainerUrl === null) {
                continue;
            }

            $counts[$postContainerUrl] = ($counts[$postContainerUrl] ?? 0) + 1;
        }

        return collect($counts)
            ->filter(static fn (int $count): bool => $count > 1)
            ->map(static fn (): bool => true)
            ->all();
    }

    private function shouldIncludePostContainer(array $listingMetadata, ?string $postContainerUrl, array $postEligibleUrls): bool
    {
        if (($listingMetadata['post_container_source'] ?? null) === 'deviantart.com'
            && is_string($listingMetadata['post_container_referrer_url'] ?? null)
            && trim((string) $listingMetadata['post_container_referrer_url']) !== '') {
            return true;
        }

        return $postContainerUrl !== null && isset($postEligibleUrls[$postContainerUrl]);
    }

    private function hasDerivedContainerMetadata(array $listingMetadata): bool
    {
        return (
            ($listingMetadata['post_container_source'] ?? null) === 'deviantart.com'
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
            $listingMetadata['page_url'] ?? null,
            $file->referrer_url,
            $file->source === 'deviantart.com' ? $file->url : null,
            $listingMetadata['user_container_referrer_url'] ?? null,
        ];
    }

    private function postCandidateUrlsForFile(File $file, array $listingMetadata): array
    {
        return [
            $listingMetadata['post_container_referrer_url'] ?? null,
            $listingMetadata['page_url'] ?? null,
            $file->referrer_url,
            $file->source === 'deviantart.com' ? $file->url : null,
        ];
    }
}
