<?php

namespace Database\Factories;

use App\Enums\ModerationFeedRemovalRunStatus;
use App\Models\ModerationFeedRemovalRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ModerationFeedRemovalRun>
 */
class ModerationFeedRemovalRunFactory extends Factory
{
    protected $model = ModerationFeedRemovalRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => ModerationFeedRemovalRunStatus::PENDING,
            'phase' => 'queued',
            'chunk_size' => 500,
            'active_rule_count' => 0,
            'rules_hash' => null,
            'scanned_count' => 0,
            'skipped_no_prompt_count' => 0,
            'matched_count' => 0,
            'updated_count' => 0,
        ];
    }
}
