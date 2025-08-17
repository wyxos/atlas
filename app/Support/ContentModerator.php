<?php

namespace App\Support;

use App\Models\ModerationRule;

class ContentModerator
{
    protected array $rules;

    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    /**
    * Load active moderation rules from the database and build a ContentModerator instance.
    */
    public static function fromDatabase(): self
    {
        $rules = ModerationRule::query()
            ->where('active', true)
            ->orderBy('id')
            ->get()
            ->map(fn (ModerationRule $rule) => $rule->toContentModeratorFormat() + ['id' => $rule->id, 'name' => $rule->name])
            ->all();

        return new self($rules);
    }

    /**
    * Return all rules that match the provided text. Each returned item is the rule array that matched.
    */
    public function matches(string $text): array
    {
        $text = mb_strtolower($text);
        $matches = [];

        foreach ($this->rules as $rule) {
            switch ($rule['type']) {
                case 'contains':
                    $matched = $this->matchTerms($text, $rule['terms'], $rule['match'] ?? 'any');
                    $unless = $rule['unless'] ?? [];
                    if ($matched && !$this->containsAny($text, $unless)) {
                        $matches[] = $rule;
                    }
                    break;

                case 'contains-combo':
                    $with = $rule['with'] ?? [];
                    if ($this->containsAny($text, $rule['terms']) && $this->containsAny($text, $with)) {
                        $matches[] = $rule;
                    }
                    break;
            }
        }

        return $matches;
    }

    public function shouldBlock(string $text): bool
    {
        return count($this->matches($text)) > 0;
    }

    protected function matchTerms(string $text, array $terms, string $mode = 'any'): bool
    {
        $hits = array_filter($terms, fn($term) => str_contains($text, mb_strtolower($term)));
        return $mode === 'all'
            ? count($hits) === count($terms)
            : count($hits) > 0;
    }

    protected function containsAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($text, mb_strtolower($term))) {
                return true;
            }
        }
        return false;
    }
}
