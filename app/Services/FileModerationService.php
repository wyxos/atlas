<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActionType;
use App\Models\File;
use App\Models\FileModerationAction;
use App\Models\ModerationRule;
use App\Services\Moderation\Moderator;
use Illuminate\Support\Collection;

final class FileModerationService extends BaseModerationService
{
    private ?Collection $activeRules = null;

    private Moderator $moderator;

    /**
     * @var array<string, array{file_id:int, action_type:string, moderation_rule_id:int, moderation_rule_name:string, created_at:mixed, updated_at:mixed}>
     */
    private array $recordedActions = [];

    public function __construct()
    {
        $this->moderator = new Moderator;
    }

    private function getPromptForFile(File $file): ?string
    {
        $payload = (array) optional($file->metadata)->payload;

        $prompt = data_get($payload, 'prompt')
            ?? data_get($file->detail_metadata, 'prompt')
            ?? data_get($file->listing_metadata, 'meta.prompt');

        if (! is_string($prompt) || $prompt === '') {
            return null;
        }

        return $prompt;
    }

    /**
     * Apply file moderation rules to files based on their action_type.
     */
    public function moderate(Collection $files): array
    {
        // Load active rules
        $this->activeRules = ModerationRule::query()
            ->where('active', true)
            ->orderBy('id', 'asc')
            ->get();

        return $this->process($files);
    }

    /**
     * Return the first active rule (by id asc) that matches this file's prompt.
     *
     * This is used for "explain" style UI (e.g. File viewer sheet), not for applying actions.
     */
    public function matchRule(File $file, ?string $actionType = null): ?ModerationRule
    {
        $query = ModerationRule::query()
            ->where('active', true)
            ->orderBy('id', 'asc');

        if (is_string($actionType) && $actionType !== '') {
            $query->where('action_type', $actionType);
        }

        $this->activeRules = $query->get();

        return $this->getMatchForFile($file);
    }

    protected function hasRules(): bool
    {
        return $this->activeRules !== null && ! $this->activeRules->isEmpty();
    }

    protected function shouldSkipFile(File $file): bool
    {
        return $this->getPromptForFile($file) === null;
    }

    protected function getMatchForFile(File $file): ?ModerationRule
    {
        $prompt = $this->getPromptForFile($file);
        if ($prompt === null) {
            return null;
        }

        foreach ($this->activeRules as $rule) {
            $this->moderator->loadRule($rule);
            if ($this->moderator->check($prompt)) {
                return $rule;
            }
        }

        return null;
    }

    protected function getActionType(object $match): string
    {
        return $match->action_type ?? ActionType::DISLIKE;
    }

    protected function resetState(): void
    {
        parent::resetState();
        $this->recordedActions = [];
    }

    protected function recordMatch(File $file, object $match, string $actionType): void
    {
        if (! in_array($actionType, [ActionType::DISLIKE, ActionType::BLACKLIST], true)) {
            return;
        }

        if (! ($match instanceof ModerationRule)) {
            return;
        }

        // Only persist the first rule that ever flagged this file for this action type.
        // This avoids repeated writes during browsing and preserves provenance even if rules later change.
        $this->recordedActions[$file->id.'-'.$actionType] = [
            'file_id' => (int) $file->id,
            'action_type' => $actionType,
            'moderation_rule_id' => (int) $match->id,
            'moderation_rule_name' => (string) $match->name,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    protected function flushRecordedMatches(): void
    {
        if ($this->recordedActions === []) {
            return;
        }

        // Insert-only: keep the first persisted reason for this file/action_type.
        FileModerationAction::query()->insertOrIgnore(array_values($this->recordedActions));
    }
}
