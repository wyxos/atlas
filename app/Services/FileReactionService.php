<?php

namespace App\Services;

use App\Jobs\DownloadFile;
use App\Jobs\SyncFileSearchIndex;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;

class FileReactionService
{
    public function __construct(
        private DownloadedFileClearService $downloadedFileClearService,
    ) {}

    /**
     * Set a reaction for a file (idempotent).
     *
     * Unlike the UI controller, this does NOT toggle off if the same reaction is set again.
     *
     * @param  array{
     *     deferHeavySideEffects?: bool,
     *     queueDownload?: bool,
     *     forceDownload?: bool,
     *     downloadRuntimeContext?: array{
     *         cookies?: list<array{
     *             name: string,
     *             value: string,
     *             domain: string,
     *             path: string,
     *             secure: bool,
     *             http_only: bool,
     *             host_only: bool,
     *             expires_at: int|null
     *         }>,
     *         user_agent?: string
     *     }
     * }  $options
     * @return array{reaction: array{type: string}|null, reacted_at: string|null, changed: bool}
     */
    public function set(
        File $file,
        User $user,
        string $type,
        array $options = [],
    ): array {
        $deferHeavySideEffects = $options['deferHeavySideEffects'] ?? false;
        $queueDownload = $options['queueDownload'] ?? true;
        $forceDownload = $options['forceDownload'] ?? false;
        $downloadRuntimeContext = $options['downloadRuntimeContext'] ?? [];
        $existingReaction = Reaction::query()
            ->where('user_id', $user->id)
            ->where('file_id', $file->id)
            ->first();

        if ($existingReaction && $existingReaction->type === $type) {
            if ($type === 'dislike') {
                $this->clearDownloadedAssetsForDislike($file, $deferHeavySideEffects);

                return [
                    'reaction' => ['type' => $existingReaction->type],
                    'reacted_at' => $existingReaction->created_at?->toIso8601String(),
                    'changed' => false,
                ];
            }

            $shouldNormalizePositiveState = in_array($type, ['love', 'like', 'funny'], true)
                && ($file->auto_disliked || $file->blacklisted_at !== null);

            if (! $shouldNormalizePositiveState) {
                if ($queueDownload) {
                    $this->dispatchDownloadFile($file->id, $forceDownload, $downloadRuntimeContext);
                }

                return [
                    'reaction' => ['type' => $existingReaction->type],
                    'reacted_at' => $existingReaction->created_at?->toIso8601String(),
                    'changed' => false,
                ];
            }

            $reaction = $this->applyReactionChange(
                $file,
                $user,
                $existingReaction,
                $type,
                $deferHeavySideEffects,
                $queueDownload,
                $forceDownload,
                $downloadRuntimeContext,
            );

            return [
                'reaction' => ['type' => $reaction->type],
                'reacted_at' => $reaction->created_at?->toIso8601String(),
                'changed' => true,
            ];
        }

        $reaction = $this->applyReactionChange(
            $file,
            $user,
            $existingReaction,
            $type,
            $deferHeavySideEffects,
            $queueDownload,
            $forceDownload,
            $downloadRuntimeContext,
        );

        return [
            'reaction' => $reaction ? ['type' => $reaction->type] : null,
            'reacted_at' => $reaction?->created_at?->toIso8601String(),
            'changed' => true,
        ];
    }

    /**
     * Toggle a reaction for a file (UI semantics).
     *
     * If the same reaction is already set, it will be removed.
     *
     * @return array{reaction: array{type: string}|null}
     */
    public function toggle(File $file, User $user, string $type): array
    {
        $existingReaction = Reaction::query()
            ->where('user_id', $user->id)
            ->where('file_id', $file->id)
            ->first();

        $metrics = app(MetricsService::class);
        $oldType = $existingReaction?->type;
        $wasBlacklisted = $file->blacklisted_at !== null;
        $isBlacklisted = $wasBlacklisted;

        if ($existingReaction && $existingReaction->type === $type) {
            if ($type === 'dislike' && $this->clearDownloadedAssetsForDislike($file)) {
                return ['reaction' => ['type' => $existingReaction->type]];
            }

            $metrics->applyReactionChange($file, $oldType, null, $wasBlacklisted, $isBlacklisted);
            $existingReaction->delete();
            // Reactions are indexed into Typesense via File::toSearchableArray(), but toggling a
            // reaction does not mutate the File model. Force a reindex so local browse filters
            // (reaction_mode, reacted_user_ids, etc.) stay in sync.
            $file->searchable();

            return ['reaction' => null];
        }

        $reaction = $this->applyReactionChange($file, $user, $existingReaction, $type);

        return [
            'reaction' => $reaction ? ['type' => $reaction->type] : null,
        ];
    }

    private function applyReactionChange(
        File $file,
        User $user,
        ?Reaction $existingReaction,
        string $type,
        bool $deferHeavySideEffects = false,
        bool $queueDownload = true,
        bool $forceDownload = false,
        array $downloadRuntimeContext = [],
    ): Reaction {
        $metrics = app(MetricsService::class);
        $oldType = $existingReaction?->type;
        $wasBlacklisted = $file->blacklisted_at !== null;
        $isBlacklisted = $wasBlacklisted;

        // Positive reactions clear auto_disliked and also clear blacklist flags.
        if (in_array($type, ['love', 'like', 'funny'], true)) {
            $updates = ['auto_disliked' => false];

            if ($file->blacklisted_at !== null) {
                $wasManual = is_string($file->blacklist_reason) && $file->blacklist_reason !== '';
                $metrics->applyBlacklistClear($file, $wasManual, false);
                $updates['blacklisted_at'] = null;
                $updates['blacklist_reason'] = null;
                $isBlacklisted = false;
            }

            $file->update($updates);
        }

        $metrics->applyReactionChange($file, $oldType, $type, $wasBlacklisted, $isBlacklisted);

        $reaction = Reaction::updateOrCreate(
            [
                'file_id' => $file->id,
                'user_id' => $user->id,
            ],
            [
                'type' => $type,
            ]
        );

        if ($type !== 'dislike' && $queueDownload) {
            $this->dispatchDownloadFile($file->id, $forceDownload, $downloadRuntimeContext);
        }

        if ($type === 'dislike') {
            $this->downloadedFileClearService->clear($file, syncSearch: false);
        }

        app(TabFileService::class)->detachFileFromUserTabs($user->id, $file->id);

        $this->syncSearch($file, $deferHeavySideEffects);

        return $reaction;
    }

    private function clearDownloadedAssetsForDislike(File $file, bool $deferHeavySideEffects = false): bool
    {
        if (! $this->downloadedFileClearService->clear($file, syncSearch: false)) {
            return false;
        }

        $this->syncSearch($file, $deferHeavySideEffects);

        return true;
    }

    /**
     * @param  array{
     *     cookies?: list<array{
     *         name: string,
     *         value: string,
     *         domain: string,
     *         path: string,
     *         secure: bool,
     *         http_only: bool,
     *         host_only: bool,
     *         expires_at: int|null
     *     }>,
     *     user_agent?: string
     * }  $downloadRuntimeContext
     */
    private function dispatchDownloadFile(int $fileId, bool $forceDownload, array $downloadRuntimeContext): void
    {
        DownloadFile::dispatch($fileId, $forceDownload, $downloadRuntimeContext)
            ->onConnection($this->asyncQueueConnection())
            ->onQueue('downloads');
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

    private function syncSearch(File $file, bool $deferHeavySideEffects): void
    {
        if ($deferHeavySideEffects) {
            SyncFileSearchIndex::dispatch($file->id)
                ->onConnection($this->asyncQueueConnection())
                ->onQueue('processing');

            return;
        }

        $file->searchable();
    }
}
