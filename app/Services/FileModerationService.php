<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActionType;
use App\Enums\BlacklistPreviewedCountMode;
use App\Models\File;
use App\Models\FileModerationAction;
use App\Models\ModerationRule;
use App\Services\Moderation\FilePromptResolver;
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

    public function __construct(
        private readonly FilePromptResolver $promptResolver,
    ) {
        $this->moderator = new Moderator;
    }

    private function getPromptForFile(File $file): ?string
    {
        return $this->promptResolver->resolve($file);
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

    /**
     * @return array{id:int,name:string,action_type:string,matched_terms:array<int,string>,reason:string,blacklist_previewed_count_mode:string}|null
     */
    public function matchRuleDetails(File $file, ?string $actionType = null): ?array
    {
        $query = ModerationRule::query()
            ->where('active', true)
            ->orderBy('id', 'asc');

        if (is_string($actionType) && $actionType !== '') {
            $query->where('action_type', $actionType);
        }

        $this->activeRules = $query->get();

        return $this->getMatchDetailsForFile($file);
    }

    /**
     * @return array{id:int,name:string,action_type:string,matched_terms:array<int,string>,reason:string,blacklist_previewed_count_mode:string}|null
     */
    public function explainPersistedRule(File $file, int $ruleId, string $fallbackName): ?array
    {
        if ($ruleId <= 0 && $fallbackName === '') {
            return null;
        }

        $rule = $ruleId > 0 ? ModerationRule::query()->find($ruleId) : null;
        if (! $rule instanceof ModerationRule) {
            return [
                'id' => $ruleId,
                'name' => $fallbackName,
                'action_type' => ActionType::BLACKLIST,
                'matched_terms' => [],
                'reason' => 'The file was previously flagged by this moderation rule.',
                'blacklist_previewed_count_mode' => BlacklistPreviewedCountMode::PRESERVE,
            ];
        }

        $prompt = $this->getPromptForFile($file);
        if ($prompt === null) {
            return $this->formatRuleDetails($rule, []);
        }

        $this->moderator->loadRule($rule);
        $matchedTerms = $this->moderator->check($prompt)
            ? $this->moderator->collectMatches($prompt)
            : [];

        return $this->formatRuleDetails($rule, $matchedTerms);
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

        $firstMatch = null;

        foreach ($this->activeRules as $rule) {
            $this->moderator->loadRule($rule);
            if ($this->moderator->check($prompt)) {
                $firstMatch ??= $rule;

                if ($rule->blacklist_previewed_count_mode === BlacklistPreviewedCountMode::FEED_REMOVED) {
                    return $rule;
                }
            }
        }

        return $firstMatch;
    }

    /**
     * @return array{id:int,name:string,action_type:string,matched_terms:array<int,string>,reason:string,blacklist_previewed_count_mode:string}|null
     */
    private function getMatchDetailsForFile(File $file): ?array
    {
        $prompt = $this->getPromptForFile($file);
        if ($prompt === null) {
            return null;
        }

        $firstMatch = null;

        foreach ($this->activeRules as $rule) {
            $this->moderator->loadRule($rule);
            if (! $this->moderator->check($prompt)) {
                continue;
            }

            $details = $this->formatRuleDetails($rule, $this->moderator->collectMatches($prompt));
            $firstMatch ??= $details;

            if ($rule->blacklist_previewed_count_mode === BlacklistPreviewedCountMode::FEED_REMOVED) {
                return $details;
            }
        }

        return $firstMatch;
    }

    /**
     * @param  array<int, string>  $matchedTerms
     * @return array{id:int,name:string,action_type:string,matched_terms:array<int,string>,reason:string,blacklist_previewed_count_mode:string}
     */
    private function formatRuleDetails(ModerationRule $rule, array $matchedTerms): array
    {
        $matchedTerms = array_values(array_filter(
            array_unique($matchedTerms),
            static fn (string $term): bool => trim($term) !== '',
        ));

        return [
            'id' => (int) $rule->id,
            'name' => (string) $rule->name,
            'action_type' => (string) $rule->action_type,
            'matched_terms' => $matchedTerms,
            'reason' => $matchedTerms === []
                ? 'The rule expression matched the prompt.'
                : 'Matched prompt terms: '.implode(', ', $matchedTerms),
            'blacklist_previewed_count_mode' => (string) $rule->blacklist_previewed_count_mode,
        ];
    }

    protected function getActionType(object $match): string
    {
        return ActionType::BLACKLIST;
    }

    protected function getBlacklistMinimumPreviewedCount(object $match): ?int
    {
        if (! ($match instanceof ModerationRule)) {
            return null;
        }

        return $match->blacklist_previewed_count_mode === BlacklistPreviewedCountMode::FEED_REMOVED
            ? FilePreviewService::FEED_REMOVED_PREVIEW_COUNT
            : null;
    }

    protected function resetState(): void
    {
        parent::resetState();
        $this->recordedActions = [];
    }

    protected function recordMatch(File $file, object $match, string $actionType): void
    {
        if ($actionType !== ActionType::BLACKLIST) {
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
