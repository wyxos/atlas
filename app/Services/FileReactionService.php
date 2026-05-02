<?php

namespace App\Services;

use App\Jobs\DownloadFile;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Services\Local\LocalBrowseIndexSyncService;

class FileReactionService
{
    /**
     * Set a reaction for a file (idempotent).
     *
     * Unlike the UI controller, this does NOT toggle off if the same reaction is set again.
     *
     * @param  array{
     *     queueDownload?: bool,
     *     forceDownload?: bool,
     *     detachFromTabsOnNoop?: bool,
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
        $queueDownload = $options['queueDownload'] ?? true;
        $forceDownload = $options['forceDownload'] ?? false;
        $detachFromTabsOnNoop = $options['detachFromTabsOnNoop'] ?? false;
        $downloadRuntimeContext = $options['downloadRuntimeContext'] ?? [];
        $existingReaction = Reaction::query()
            ->where('user_id', $user->id)
            ->where('file_id', $file->id)
            ->first();

        if ($existingReaction && $existingReaction->type === $type) {
            $shouldNormalizePositiveState = in_array($type, ['love', 'like', 'funny'], true)
                && (
                    $file->auto_blacklisted
                    || $file->blacklisted_at !== null
                    || (int) $file->previewed_count >= FilePreviewService::FEED_REMOVED_PREVIEW_COUNT
                );

            if (! $shouldNormalizePositiveState) {
                if ($queueDownload) {
                    $this->dispatchDownloadFile($file->id, $forceDownload, $downloadRuntimeContext);
                }
                if ($detachFromTabsOnNoop) {
                    app(TabFileService::class)->detachFileFromUserTabs($user->id, $file->id);
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
            $metrics->applyReactionChange($file, $oldType, null, $wasBlacklisted, $isBlacklisted);
            $existingReaction->delete();
            app(LocalBrowseIndexSyncService::class)->syncFilesByIds([$file->id]);
            app(LocalBrowseIndexSyncService::class)->syncReactionsForFileIds([$file->id]);

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
        bool $queueDownload = true,
        bool $forceDownload = false,
        array $downloadRuntimeContext = [],
    ): Reaction {
        $metrics = app(MetricsService::class);
        $oldType = $existingReaction?->type;
        $wasBlacklisted = $file->blacklisted_at !== null;
        $isBlacklisted = $wasBlacklisted;
        $hasTerminalPreviewCount = (int) $file->previewed_count >= FilePreviewService::FEED_REMOVED_PREVIEW_COUNT;

        // Positive reactions recover a blacklisted file and queue/download as normal.
        if (in_array($type, ['love', 'like', 'funny'], true)) {
            $updates = ['auto_blacklisted' => false];

            $metrics->applyAutoBlacklistClear($file);

            if ($file->blacklisted_at !== null) {
                $metrics->applyBlacklistClear($file, false);
                $updates['blacklisted_at'] = null;
                $isBlacklisted = false;
            }

            if ($wasBlacklisted || $hasTerminalPreviewCount) {
                $updates['previewed_count'] = FilePreviewService::RECOVERED_PREVIEW_COUNT;
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

        if ($queueDownload) {
            $this->dispatchDownloadFile($file->id, $forceDownload, $downloadRuntimeContext);
        }

        app(TabFileService::class)->detachFileFromUserTabs($user->id, $file->id);
        app(LocalBrowseIndexSyncService::class)->syncFilesByIds([$file->id]);
        app(LocalBrowseIndexSyncService::class)->syncReactionsForFileIds([$file->id]);

        return $reaction;
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
}
