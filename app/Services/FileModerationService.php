<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActionType;
use App\Models\File;
use App\Models\ModerationRule;
use App\Services\Moderation\Moderator;
use Illuminate\Support\Collection;

final class FileModerationService extends BaseModerationService
{
    private ?Collection $activeRules = null;

    private Moderator $moderator;

    public function __construct()
    {
        $this->moderator = new Moderator;
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

    protected function hasRules(): bool
    {
        return $this->activeRules !== null && ! $this->activeRules->isEmpty();
    }

    protected function shouldSkipFile(File $file): bool
    {
        $payload = (array) optional($file->metadata)->payload;
        // Support both metadata payload and listing_metadata fallback (for Browser.php)
        $prompt = data_get($payload, 'prompt') ?? data_get($file->listing_metadata, 'meta.prompt');

        return ! is_string($prompt) || $prompt === '';
    }

    protected function getMatchForFile(File $file): ?ModerationRule
    {
        $payload = (array) optional($file->metadata)->payload;
        $prompt = data_get($payload, 'prompt') ?? data_get($file->listing_metadata, 'meta.prompt');

        if (! is_string($prompt) || $prompt === '') {
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
}
