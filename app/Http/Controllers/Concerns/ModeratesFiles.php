<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ModerationRule;
use App\Services\Moderation\Moderator;
use Illuminate\Support\Collection;

trait ModeratesFiles
{
    /**
     * Apply moderation rules to identify files that should be flagged for auto-dislike.
     * Does NOT auto-dislike immediately - returns IDs for UI to queue with countdown.
     *
     * @return array{flaggedIds:array<int>, moderationData:array<int, array>}
     */
    protected function moderateFiles(Collection $files): array
    {
        if ($files->isEmpty()) {
            return [
                'flaggedIds' => [],
                'moderationData' => [],
            ];
        }

        $activeRules = ModerationRule::query()->where('active', true)->orderBy('id', 'asc')->get();
        if ($activeRules->isEmpty()) {
            return [
                'flaggedIds' => [],
                'moderationData' => [],
            ];
        }

        $moderator = new Moderator;
        $flaggedIds = [];
        $moderationData = [];

        foreach ($files as $file) {
            // Skip files already auto-disliked
            if ($file->auto_disliked) {
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
                $flaggedIds[] = $file->id;
                $moderationData[$file->id] = [
                    'reason' => 'moderation:rule',
                    'rule_id' => $matchedRule->id,
                    'rule_name' => $matchedRule->name,
                    'options' => $matchedRule->options ?? null,
                    'hits' => array_values($hits),
                ];
            }
        }

        return [
            'flaggedIds' => $flaggedIds,
            'moderationData' => $moderationData,
        ];
    }
}
