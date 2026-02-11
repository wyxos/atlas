<?php

namespace App\Services;

use App\Jobs\DownloadFile;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;

class FileReactionService
{
    /**
     * Set a reaction for a file (idempotent).
     *
     * Unlike the UI controller, this does NOT toggle off if the same reaction is set again.
     *
     * @return array{reaction: array{type: string}|null, changed: bool}
     */
    public function set(File $file, User $user, string $type): array
    {
        $existingReaction = Reaction::query()
            ->where('user_id', $user->id)
            ->where('file_id', $file->id)
            ->first();

        if ($existingReaction && $existingReaction->type === $type) {
            // No-op: keep the existing reaction.
            if ($type !== 'dislike') {
                DownloadFile::dispatch($file->id);
            }

            return [
                'reaction' => ['type' => $existingReaction->type],
                'changed' => false,
            ];
        }

        $reaction = $this->applyReactionChange($file, $user, $existingReaction, $type);

        return [
            'reaction' => $reaction ? ['type' => $reaction->type] : null,
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

    private function applyReactionChange(File $file, User $user, ?Reaction $existingReaction, string $type): Reaction
    {
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

        if ($type !== 'dislike') {
            DownloadFile::dispatch($file->id);
        }

        app(TabFileService::class)->detachFileFromUserTabs($user->id, $file->id);

        // Ensure the search index reflects the new reaction arrays.
        $file->searchable();

        return $reaction;
    }
}
