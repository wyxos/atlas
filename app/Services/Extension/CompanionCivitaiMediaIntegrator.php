<?php

namespace App\Services\Extension;

use App\Models\Container;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Services\BrowsePersister;
use App\Services\CivitAiImages;
use App\Services\FileBlacklistService;
use App\Services\FileModerationService;
use App\Services\FileReactionService;
use App\Support\StableFileIdentity;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CompanionCivitaiMediaIntegrator
{
    public function __construct(
        private readonly CompanionCivitaiMediaPreparer $preparer,
        private readonly BrowsePersister $browsePersister,
        private readonly ExtensionActiveTransferLookup $activeTransferLookup,
        private readonly FileBlacklistService $fileBlacklistService,
        private readonly FileModerationService $fileModerationService,
        private readonly FileReactionService $fileReactionService,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function status(array $items, User $user): array
    {
        $preparedItems = collect($items)
            ->values()
            ->map(fn (array $item, int $index): array => $this->preparer->prepare($item, $index));

        $knownFiles = $this->knownFilesFor($preparedItems);
        $fileIds = $knownFiles
            ->filter()
            ->map(fn (File $file): int => (int) $file->id)
            ->unique()
            ->values();
        $reactionsByFileId = $this->reactionsByFileId($fileIds, $user);
        $activeTransfersByFileId = $this->activeTransferLookup->byFileId($fileIds->all());

        return $preparedItems
            ->map(fn (array $prepared) => $this->formatStatusItem(
                $prepared,
                $knownFiles->get($prepared['key']),
                $reactionsByFileId,
                $activeTransfersByFileId,
            ))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $downloadRuntimeContext
     * @return array<string, mixed>
     */
    public function react(array $validated, User $user, array $downloadRuntimeContext = []): array
    {
        $prepared = $this->preparer->prepare($validated['item'], 0);
        $file = $this->persistPreparedItem($prepared);
        $reactionType = (string) $validated['type'];
        $downloadBehavior = (string) ($validated['download_behavior'] ?? 'queue');
        $downloadRequested = false;
        $reaction = null;
        $reactedAt = null;

        if ($reactionType === 'blacklist') {
            $this->fileBlacklistService->apply(
                [$file],
                (int) $user->id,
                queueContainerAutoBlacklistEvaluation: true,
            );
            $file->refresh();
        } else {
            $downloadRequested = $downloadBehavior !== 'skip';
            $result = $this->fileReactionService->set(
                $file,
                $user,
                $reactionType,
                [
                    'queueDownload' => $downloadRequested,
                    'forceDownload' => $downloadBehavior === 'force',
                    'downloadRuntimeContext' => $downloadRuntimeContext,
                ],
            );
            $reaction = $result['reaction'] ?? null;
            $reactedAt = $result['reacted_at'] ?? null;
            $file->refresh();
        }

        $activeTransfer = $this->activeTransferLookup->forFileId((int) $file->id);

        return [
            'file' => $this->filePayload($file),
            'reaction' => $reaction,
            'reacted_at' => $reactedAt,
            'download' => [
                'requested' => $downloadRequested,
                'transfer_id' => $activeTransfer?->id,
                'status' => $activeTransfer?->status,
                'progress_percent' => $activeTransfer?->last_broadcast_percent,
                'downloaded_at' => $file->downloaded_at?->toIso8601String(),
            ],
            'blacklisted_at' => $file->blacklisted_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $preparedItems
     * @return Collection<string, File|null>
     */
    private function knownFilesFor(Collection $preparedItems): Collection
    {
        $sourceIds = $preparedItems
            ->map(fn (array $prepared): string => trim((string) data_get($prepared, 'transformed.file.source_id', '')))
            ->filter()
            ->unique()
            ->values();
        $urlHashes = $preparedItems
            ->map(fn (array $prepared): string => $this->urlHash(data_get($prepared, 'transformed.file.url')))
            ->filter()
            ->unique()
            ->values();
        $referrerHashes = $preparedItems
            ->map(fn (array $prepared): string => $this->urlHash(data_get($prepared, 'transformed.file.referrer_url')))
            ->filter()
            ->unique()
            ->values();

        if ($sourceIds->isEmpty() && $urlHashes->isEmpty() && $referrerHashes->isEmpty()) {
            return collect();
        }

        $files = File::query()
            ->with(['containers', 'metadata'])
            ->where(function ($query) use ($sourceIds, $urlHashes, $referrerHashes): void {
                if ($sourceIds->isNotEmpty()) {
                    $query->orWhere(function ($query) use ($sourceIds): void {
                        $query->where('source', CivitAiImages::SOURCE)
                            ->whereIn('source_id', $sourceIds->all());
                    });
                }

                if ($urlHashes->isNotEmpty()) {
                    $query->orWhereIn('url_hash', $urlHashes->all());
                }

                if ($referrerHashes->isNotEmpty()) {
                    $query->orWhereIn('referrer_url_hash', $referrerHashes->all());
                }
            })
            ->orderByDesc('downloaded')
            ->latest('updated_at')
            ->get();

        $bySourceId = $files
            ->filter(fn (File $file): bool => $file->source === CivitAiImages::SOURCE && trim((string) $file->source_id) !== '')
            ->unique('source_id')
            ->keyBy('source_id');
        $byReferrerHash = $files
            ->filter(fn (File $file): bool => trim((string) $file->referrer_url_hash) !== '')
            ->unique('referrer_url_hash')
            ->keyBy('referrer_url_hash');
        $byUrlHash = $files
            ->filter(fn (File $file): bool => trim((string) $file->url_hash) !== '')
            ->unique('url_hash')
            ->keyBy('url_hash');

        return $preparedItems->mapWithKeys(function (array $prepared) use ($bySourceId, $byReferrerHash, $byUrlHash): array {
            $fileRow = $prepared['transformed']['file'];
            $sourceId = trim((string) ($fileRow['source_id'] ?? ''));
            $referrerHash = $this->urlHash($fileRow['referrer_url'] ?? null);
            $urlHash = $this->urlHash($fileRow['url'] ?? null);

            return [
                $prepared['key'] => $bySourceId->get($sourceId)
                    ?? $byReferrerHash->get($referrerHash)
                    ?? $byUrlHash->get($urlHash),
            ];
        });
    }

    /**
     * @param  Collection<int, int>  $fileIds
     * @return Collection<int, Reaction>
     */
    private function reactionsByFileId(Collection $fileIds, User $user): Collection
    {
        if ($fileIds->isEmpty()) {
            return collect();
        }

        return Reaction::query()
            ->where('user_id', $user->id)
            ->whereIn('file_id', $fileIds->all())
            ->get()
            ->keyBy('file_id');
    }

    /**
     * @param  Collection<int, Reaction>  $reactionsByFileId
     * @param  array<int, \App\Models\DownloadTransfer>  $activeTransfersByFileId
     * @return array<string, mixed>
     */
    private function formatStatusItem(
        array $prepared,
        ?File $file,
        Collection $reactionsByFileId,
        array $activeTransfersByFileId,
    ): array {
        $reaction = $file ? $reactionsByFileId->get($file->id) : null;
        $downloaded = (bool) ($file?->downloaded ?? false) || $file?->downloaded_at !== null;
        $blacklisted = $file?->blacklisted_at !== null;
        $autoBlacklisted = (bool) ($file?->auto_blacklisted ?? false);
        $filterReasons = $this->filterReasonsFor($prepared, $file, $reaction, $downloaded);
        $activeTransfer = $file ? ($activeTransfersByFileId[(int) $file->id] ?? null) : null;

        return [
            'request_id' => $prepared['request_id'],
            'request_index' => $prepared['request_index'],
            'exists' => $file !== null,
            'known_file' => $file !== null,
            'file_id' => $file?->id,
            'downloaded' => $downloaded,
            'downloaded_at' => $file?->downloaded_at?->toIso8601String(),
            'blacklisted' => $blacklisted,
            'blacklisted_at' => $file?->blacklisted_at?->toIso8601String(),
            'auto_blacklisted' => $autoBlacklisted,
            'reaction' => $reaction?->type,
            'reacted_at' => $reaction?->created_at?->toIso8601String(),
            'filtered' => $filterReasons !== [],
            'ignored' => $filterReasons !== [],
            'filter_reasons' => $filterReasons,
            'download' => [
                'transfer_id' => $activeTransfer?->id,
                'status' => $activeTransfer?->status,
                'progress_percent' => $activeTransfer?->last_broadcast_percent,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filterReasonsFor(array $prepared, ?File $file, ?Reaction $reaction, bool $downloaded): array
    {
        $reasons = [];

        if ($file?->blacklisted_at !== null) {
            $reasons[] = [
                'type' => 'blacklisted',
                'name' => 'Atlas blacklist',
            ];
        }

        if ((bool) ($file?->auto_blacklisted ?? false)) {
            $reasons[] = [
                'type' => 'auto_blacklisted',
                'name' => 'Atlas auto blacklist',
            ];
        }

        if ($reasons !== [] || $reaction !== null || $downloaded) {
            return $reasons;
        }

        $container = $this->blacklistedContainerFor($prepared, $file);
        if ($container) {
            $reasons[] = [
                'type' => 'container_blacklist',
                'id' => (int) $container->id,
                'name' => "{$container->type} {$container->source_id}",
            ];
        }

        $ruleDetails = $this->fileModerationService->matchRuleDetails(
            $file ?? $this->preparer->unsavedFileFor($prepared),
            'blacklist',
        );
        if (is_array($ruleDetails)) {
            $reasons[] = [
                'type' => 'moderation_rule',
                'id' => $ruleDetails['id'] ?? null,
                'name' => $ruleDetails['name'] ?? 'Moderation rule',
                'reason' => $ruleDetails['reason'] ?? null,
                'matched_terms' => $ruleDetails['matched_terms'] ?? [],
            ];
        }

        return $reasons;
    }

    private function blacklistedContainerFor(array $prepared, ?File $file): ?Container
    {
        if ($file) {
            $container = $file->containers
                ->first(fn (Container $container): bool => $container->blacklisted_at !== null);
            if ($container instanceof Container) {
                return $container;
            }
        }

        $candidates = $this->containerCandidatesFor($prepared);
        if ($candidates === []) {
            return null;
        }

        return Container::query()
            ->where('source', CivitAiImages::SOURCE)
            ->whereNotNull('blacklisted_at')
            ->where(function ($query) use ($candidates): void {
                foreach ($candidates as $candidate) {
                    $query->orWhere(function ($query) use ($candidate): void {
                        $query->where('type', $candidate['type'])
                            ->where('source_id', $candidate['source_id']);
                    });
                }
            })
            ->orderBy('id')
            ->first();
    }

    /**
     * @return list<array{type: string, source_id: string}>
     */
    private function containerCandidatesFor(array $prepared): array
    {
        $listingMetadata = $this->preparer->listingMetadataFor($prepared);
        $candidates = [];

        $postId = (int) ($listingMetadata['postId'] ?? 0);
        if ($postId > 0) {
            $candidates[] = ['type' => 'Post', 'source_id' => (string) $postId];
        }

        $username = trim((string) ($listingMetadata['username'] ?? ''));
        if ($username !== '') {
            $candidates[] = ['type' => 'User', 'source_id' => $username];
        }

        $resourceContainers = $listingMetadata['resource_containers'] ?? [];
        if (is_array($resourceContainers)) {
            foreach ($resourceContainers as $container) {
                if (! is_array($container)) {
                    continue;
                }

                $type = trim((string) ($container['type'] ?? ''));
                $sourceId = trim((string) ($container['modelVersionId'] ?? ''));
                if (in_array($type, ['Checkpoint', 'LoRA'], true) && $sourceId !== '') {
                    $candidates[] = ['type' => $type, 'source_id' => $sourceId];
                }
            }
        }

        return array_values(array_unique($candidates, SORT_REGULAR));
    }

    private function persistPreparedItem(array $prepared): File
    {
        $this->browsePersister->persist([$prepared['transformed']]);
        $fileRow = $prepared['transformed']['file'];
        $file = StableFileIdentity::findExistingFile(
            (string) ($fileRow['source'] ?? ''),
            isset($fileRow['source_id']) ? (string) $fileRow['source_id'] : null,
            isset($fileRow['referrer_url']) ? (string) $fileRow['referrer_url'] : null,
        );

        if (! $file instanceof File) {
            $urlHash = $this->urlHash($fileRow['url'] ?? null);
            $file = $urlHash !== ''
                ? File::query()->where('url_hash', $urlHash)->first()
                : null;
        }

        if (! $file instanceof File) {
            throw ValidationException::withMessages([
                'item' => 'The submitted Civitai media item could not be persisted.',
            ]);
        }

        return $file->load(['containers', 'metadata']);
    }

    /**
     * @return array<string, mixed>
     */
    private function filePayload(File $file): array
    {
        return [
            'id' => $file->id,
            'source' => $file->source,
            'source_id' => $file->source_id,
            'url' => $file->url,
            'referrer_url' => $file->referrer_url,
            'preview_url' => $file->preview_url,
        ];
    }

    private function urlHash(mixed $url): string
    {
        $url = is_string($url) ? trim($url) : '';

        return $url !== '' ? hash('sha256', $url) : '';
    }
}
