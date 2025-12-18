<?php

namespace App\Http\Controllers\Concerns;

use App\Jobs\DeleteAutoDislikedFileJob;
use App\Models\ModerationRule;
use App\Models\Reaction;
use App\Services\Moderation\Moderator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

trait ModeratesFiles
{
    /**
     * Apply moderation rules to files based on their action_type.
     * - ui_countdown: Returns IDs for UI to queue with countdown
     * - auto_dislike: Immediately sets auto_disliked = true, creates dislike reaction, and dispatches delete job
     * - blacklist: Immediately sets blacklisted_at = now() and dispatches delete job
     *
     * @return array{flaggedIds:array<int>, moderationData:array<int, array>, processedIds:array<int>}
     */
    protected function moderateFiles(Collection $files): array
    {
        if ($files->isEmpty()) {
            return [
                'flaggedIds' => [],
                'moderationData' => [],
                'processedIds' => [],
            ];
        }

        $activeRules = ModerationRule::query()->where('active', true)->orderBy('id', 'asc')->get();
        if ($activeRules->isEmpty()) {
            return [
                'flaggedIds' => [],
                'moderationData' => [],
                'processedIds' => [],
            ];
        }

        $moderator = new Moderator;
        $flaggedIds = []; // For ui_countdown action type
        $moderationData = [];
        $autoDislikeFileIds = []; // Files to auto-dislike
        $blacklistFileIds = []; // Files to blacklist
        $filesToDelete = []; // Files with paths that need delete jobs

        foreach ($files as $file) {
            // Skip files already auto-disliked or blacklisted
            if ($file->auto_disliked || $file->blacklisted_at !== null) {
                continue;
            }

            $payload = (array) optional($file->metadata)->payload;
            // Support both metadata payload and listing_metadata fallback (for Browser.php)
            $prompt = data_get($payload, 'prompt') ?? data_get($file->listing_metadata, 'meta.prompt');
            if (! is_string($prompt) || $prompt === '') {
                continue;
            }

            $matchedRule = null;
            $hits = [];
            foreach ($activeRules as $rule) {
                $moderator->loadRule($rule);
                if ($moderator->check($prompt)) {
                    $matchedRule = $rule;
                    $hits = $moderator->collectMatches($prompt);
                    break;
                }
            }

            if ($matchedRule) {
                $actionType = $matchedRule->action_type ?? ModerationRule::ACTION_UI_COUNTDOWN;

                $moderationData[$file->id] = [
                    'reason' => 'moderation:rule',
                    'rule_id' => $matchedRule->id,
                    'rule_name' => $matchedRule->name,
                    'options' => $matchedRule->options ?? null,
                    'hits' => array_values($hits),
                ];

                // Handle different action types
                if ($actionType === ModerationRule::ACTION_UI_COUNTDOWN) {
                    // Queue for UI countdown
                    $flaggedIds[] = $file->id;
                } elseif ($actionType === ModerationRule::ACTION_AUTO_DISLIKE) {
                    // Collect for batch processing
                    $autoDislikeFileIds[] = $file->id;
                    if (! empty($file->path)) {
                        $filesToDelete[] = $file->path;
                    }
                } elseif ($actionType === ModerationRule::ACTION_BLACKLIST) {
                    // Collect for batch processing
                    $blacklistFileIds[] = $file->id;
                    if (! empty($file->path)) {
                        $filesToDelete[] = $file->path;
                    }
                }
            }
        }

        // Batch update auto-disliked files
        if (! empty($autoDislikeFileIds)) {
            \App\Models\File::whereIn('id', $autoDislikeFileIds)->update(['auto_disliked' => true]);

            // Batch create dislike reactions for auto-disliked files
            $user = Auth::user();
            if ($user) {
                $reactionsToInsert = array_map(function ($fileId) use ($user) {
                    return [
                        'file_id' => $fileId,
                        'user_id' => $user->id,
                        'type' => 'dislike',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }, $autoDislikeFileIds);

                // Check for existing reactions to avoid duplicates
                $existingReactions = Reaction::whereIn('file_id', $autoDislikeFileIds)
                    ->where('user_id', $user->id)
                    ->pluck('file_id')
                    ->toArray();

                $newReactionsToInsert = array_filter($reactionsToInsert, function ($reaction) use ($existingReactions) {
                    return ! in_array($reaction['file_id'], $existingReactions);
                });

                if (! empty($newReactionsToInsert)) {
                    Reaction::insert($newReactionsToInsert);
                }
            }
        }

        // Batch update blacklisted files
        if (! empty($blacklistFileIds)) {
            \App\Models\File::whereIn('id', $blacklistFileIds)->update(['blacklisted_at' => now()]);
        }

        // Dispatch delete jobs for files with paths
        foreach ($filesToDelete as $path) {
            DeleteAutoDislikedFileJob::dispatch($path);
        }

        $processedIds = array_merge($autoDislikeFileIds, $blacklistFileIds);

        return [
            'flaggedIds' => $flaggedIds,
            'moderationData' => $moderationData,
            'processedIds' => $processedIds,
        ];
    }
}
