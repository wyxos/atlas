<?php

namespace App\Services\Moderation;

use App\Enums\ModerationFeedRemovalRunStatus;
use App\Models\ModerationFeedRemovalRun;

class FeedRemovalBackfillPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function run(ModerationFeedRemovalRun $run, ?string $currentRulesHash = null): array
    {
        $rulesMatchCurrent = $currentRulesHash !== null && $run->rules_hash !== null
            ? hash_equals($run->rules_hash, $currentRulesHash)
            : null;

        return [
            'id' => $run->id,
            'status' => $run->status,
            'phase' => $run->phase,
            'chunk_size' => (int) $run->chunk_size,
            'active_rule_count' => (int) $run->active_rule_count,
            'scanned_count' => (int) $run->scanned_count,
            'skipped_no_prompt_count' => (int) $run->skipped_no_prompt_count,
            'matched_count' => (int) $run->matched_count,
            'updated_count' => (int) $run->updated_count,
            'rules_match_current' => $rulesMatchCurrent,
            'can_apply' => $run->status === ModerationFeedRemovalRunStatus::PREVIEWED
                && (int) $run->matched_count > 0
                && $rulesMatchCurrent === true,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'applied_at' => $run->applied_at?->toIso8601String(),
            'error' => $run->error,
            'created_at' => $run->created_at?->toIso8601String(),
            'updated_at' => $run->updated_at?->toIso8601String(),
        ];
    }
}
