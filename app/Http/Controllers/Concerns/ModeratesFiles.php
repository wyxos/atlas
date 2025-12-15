<?php

namespace App\Http\Controllers\Concerns;

use App\Jobs\DeleteAutoDislikedFileJob;
use App\Models\ModerationRule;
use App\Services\Moderation\Moderator;
use Illuminate\Support\Collection;

trait ModeratesFiles
{
    /**
     * Apply moderation rules to files based on their action_type.
     * - ui_countdown: Returns IDs for UI to queue with countdown
     * - auto_dislike: Immediately sets auto_disliked = true and dispatches delete job
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
        $processedIds = []; // Files that were immediately processed (auto_dislike or blacklist)

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
                    // Immediately auto-dislike
                    $file->auto_disliked = true;
                    $file->save();
                    $processedIds[] = $file->id;

                    // Dispatch delete job if file has a path
                    if (! empty($file->path)) {
                        DeleteAutoDislikedFileJob::dispatch($file->path);
                    }
                } elseif ($actionType === ModerationRule::ACTION_BLACKLIST) {
                    // Immediately blacklist
                    $file->blacklisted_at = now();
                    $file->save();
                    $processedIds[] = $file->id;

                    // Dispatch delete job if file has a path
                    if (! empty($file->path)) {
                        DeleteAutoDislikedFileJob::dispatch($file->path);
                    }
                }
            }
        }

        return [
            'flaggedIds' => $flaggedIds,
            'moderationData' => $moderationData,
            'processedIds' => $processedIds,
        ];
    }
}
