<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Extension\ExtensionBatchReactionService;
use App\Services\Extension\ExtensionContainerMetadataService;
use App\Services\Extension\ExtensionReactionProcessor;
use App\Services\FileBlacklistService;
use App\Services\FilePreviewService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessExtensionBatchReaction implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $runtimeContext
     */
    public function __construct(
        public int $userId,
        public string $extensionChannel,
        public array $items,
        public string $reactionType,
        public string $downloadBehavior,
        public array $runtimeContext = [],
    ) {
        $this->items = array_values($this->items);
        $this->runtimeContext['user_id'] ??= $this->userId;

        $this->onConnection($this->asyncQueueConnection());
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(
        ExtensionReactionProcessor $reactionProcessor,
        ExtensionContainerMetadataService $containerMetadataService,
        FileBlacklistService $fileBlacklistService,
        ExtensionBatchReactionService $batchReactionService,
    ): void {
        $user = User::query()->find($this->userId);
        if (! $user instanceof User || $this->items === []) {
            return;
        }

        $files = [];

        foreach ($this->items as $item) {
            $file = $reactionProcessor->fileForExtensionItem(
                $this->processorItemFromPayload($item),
                $containerMetadataService,
                $user,
                $this->extensionChannel,
                $this->listingMetadataFromPayload($item),
            );

            $files[(int) $file->id] = $file;
        }

        if ($files === []) {
            return;
        }

        if ($this->reactionType === 'blacklist') {
            $fileBlacklistService->apply(
                array_values($files),
                (int) $user->id,
                minimumPreviewedCount: FilePreviewService::FEED_REMOVED_PREVIEW_COUNT,
                autoBlacklisted: false,
                queueContainerAutoBlacklistEvaluation: true,
            );

            return;
        }

        $batchReactionService->setMany(
            array_values($files),
            $user,
            $this->reactionType,
            [
                'downloadRuntimeContext' => $this->runtimeContext,
                'forceDownload' => $this->downloadBehavior === 'force',
                'queueDownload' => $this->downloadBehavior !== 'skip',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function processorItemFromPayload(array $payload): array
    {
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        return [
            'page_url' => $payload['referrer_url'] ?? null,
            'referrer_url' => $payload['referrer_url'] ?? null,
            'referrer_url_hash_aware' => $payload['referrer_url'] ?? null,
            'tag_name' => $this->tagNameFromMetadata($metadata),
            'url' => $payload['asset_url'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function listingMetadataFromPayload(array $payload): array
    {
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        return array_filter([
            'asset_type' => $metadata['asset_type'] ?? null,
            'page_title' => $metadata['page_title'] ?? null,
            'resolution' => $metadata['resolution'] ?? null,
            'source' => $payload['source'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function tagNameFromMetadata(array $metadata): ?string
    {
        return match ($metadata['asset_type'] ?? null) {
            'audio' => 'audio',
            'image' => 'img',
            'video' => 'video',
            default => null,
        };
    }

    private function asyncQueueConnection(): string
    {
        $connection = (string) config('downloads.queue_connection', config('queue.default', 'database'));
        $normalized = strtolower(trim($connection));

        if ($normalized === '' || $normalized === 'sync') {
            return 'database';
        }

        return $connection;
    }
}
