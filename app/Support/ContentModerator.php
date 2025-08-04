<?php

namespace App\Support;

class ContentModerator
{
    protected array $rules;

    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    public function shouldBlock(string $text): bool
    {
        $text = mb_strtolower($text);

        foreach ($this->rules as $rule) {
            switch ($rule['type']) {
                case 'contains':
                    $matched = $this->matchTerms($text, $rule['terms'], $rule['match'] ?? 'any');
                    $unless = $rule['unless'] ?? [];
                    if ($matched && !$this->containsAny($text, $unless)) {
                        return true;
                    }
                    break;

                case 'contains-combo':
                    if ($this->containsAny($text, $rule['terms']) && $this->containsAny($text, $rule['with'])) {
                        return true;
                    }
                    break;
            }
        }

        return false;
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
