<?php

declare(strict_types=1);

namespace App\Services\Moderation;

use App\Models\ModerationRule as ModerationRuleModel;

final class Moderator
{
    /**
     * The root rule node used to evaluate the input text.
     */
    private array $rule = [];

    /**
     * Load the rule tree from a plain array (backward-compatible).
     */
    public function loadRules(array $rule): void
    {
        $this->rule = $rule;
    }

    /**
     * Load the rule from a ModerationRule model.
     */
    public function loadRule(ModerationRuleModel $rule): void
    {
        $this->rule = $rule->toNode();
    }

    /**
     * Check if the provided text should be blocked according to the loaded rules.
     */
    public function check(string $text): bool
    {
        if ($this->rule === []) {
            return false;
        }

        return $this->evaluateNode($this->rule, $text);
    }

    /**
     * Collect the specific terms from the loaded rule that matched the given text.
     * Returns a de-duplicated list of matched terms (original casing as stored in the rule).
     */
    public function collectMatches(string $text): array
    {
        if ($this->rule === []) {
            return [];
        }

        $hits = $this->gatherNodeMatches($this->rule, $text);

        // De-duplicate case-insensitively but preserve first encountered casing
        $seen = [];
        $unique = [];
        foreach ($hits as $h) {
            $key = mb_strtolower($h);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $h;
            }
        }

        return $unique;
    }

    /**
     * Recursively evaluate a rule node against the input text.
     */
    private function evaluateNode(array $node, string $text): bool
    {
        $op = $node['op'] ?? 'any';

        switch ($op) {
            case 'any':
                return $this->matchAny($text, $node);

            case 'all':
                return $this->matchAll($text, $node);

            case 'not_any':
                return $this->matchNotAny($text, $node);

            case 'at_least':
                return $this->matchAtLeast($text, $node);

            case 'and':
                return $this->matchAnd($text, $node);

            case 'or':
                return $this->matchOr($text, $node);

            default:
                // Unknown operator: be conservative and do not block
                return false;
        }
    }

    private function gatherNodeMatches(array $node, string $text): array
    {
        $op = $node['op'] ?? 'any';

        switch ($op) {
            case 'any':
            case 'all':
            case 'not_any':
            case 'at_least':
                $terms = $this->sanitizeTerms($node['terms'] ?? []);
                if ($terms === []) {
                    return [];
                }
                $options = $this->extractOptions($node);
                $hits = [];
                foreach ($terms as $term) {
                    if ($this->termMatches($text, $term, $options)) {
                        $hits[] = $term;
                    }
                }

                return $hits;

            case 'and':
            case 'or':
                $children = $node['children'] ?? [];
                if (! is_array($children) || $children === []) {
                    return [];
                }
                $hits = [];
                foreach ($children as $child) {
                    if (! is_array($child)) {
                        continue;
                    }
                    $hits = array_merge($hits, $this->gatherNodeMatches($child, $text));
                }

                return $hits;

            default:
                return [];
        }
    }

    private function matchAny(string $text, array $node): bool
    {
        $terms = $this->sanitizeTerms($node['terms'] ?? []);
        if ($terms === []) {
            return false;
        }

        $options = $this->extractOptions($node);

        foreach ($terms as $term) {
            if ($this->termMatches($text, $term, $options)) {
                return true;
            }
        }

        return false;
    }

    private function matchAll(string $text, array $node): bool
    {
        $terms = $this->sanitizeTerms($node['terms'] ?? []);
        if ($terms === []) {
            return false;
        }

        $options = $this->extractOptions($node);

        foreach ($terms as $term) {
            if (! $this->termMatches($text, $term, $options)) {
                return false;
            }
        }

        return true;
    }

    private function matchNotAny(string $text, array $node): bool
    {
        $terms = $this->sanitizeTerms($node['terms'] ?? []);
        if ($terms === []) {
            // No forbidden terms specified; treat as satisfied (i.e., none present)
            return true;
        }

        $options = $this->extractOptions($node);

        foreach ($terms as $term) {
            if ($this->termMatches($text, $term, $options)) {
                return false;
            }
        }

        return true;
    }

    private function matchAtLeast(string $text, array $node): bool
    {
        $terms = $this->sanitizeTerms($node['terms'] ?? []);
        $min = isset($node['min']) ? (int) $node['min'] : 0;

        if ($terms === [] || $min <= 0) {
            return false;
        }

        $options = $this->extractOptions($node);

        $count = 0;
        foreach ($terms as $term) {
            if ($this->termMatches($text, $term, $options)) {
                $count++;
                if ($count >= $min) {
                    return true;
                }
            }
        }

        return false;
    }

    private function matchAnd(string $text, array $node): bool
    {
        $children = $node['children'] ?? [];
        if (! is_array($children) || $children === []) {
            return false;
        }

        foreach ($children as $child) {
            if (! is_array($child)) {
                return false;
            }
            if (! $this->evaluateNode($child, $text)) {
                return false;
            }
        }

        return true;
    }

    private function matchOr(string $text, array $node): bool
    {
        $children = $node['children'] ?? [];
        if (! is_array($children) || $children === []) {
            return false;
        }

        foreach ($children as $child) {
            if (! is_array($child)) {
                continue;
            }
            if ($this->evaluateNode($child, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clean and normalize the list of terms, dropping empties and non-strings.
     *
     * @param  array<int, mixed>  $terms
     * @return array<int, string>
     */
    private function sanitizeTerms(array $terms): array
    {
        $clean = [];
        foreach ($terms as $t) {
            if (is_string($t)) {
                $trimmed = trim($t);
                if ($trimmed !== '') {
                    $clean[] = $trimmed;
                }
            }
        }

        return $clean;
    }

    /**
     * Extract matching options with sensible defaults.
     *
     * @return array{case_sensitive: bool, whole_word: bool}
     */
    private function extractOptions(array $node): array
    {
        $opts = is_array($node['options'] ?? null) ? $node['options'] : [];

        return [
            'case_sensitive' => (bool) ($opts['case_sensitive'] ?? false),
            'whole_word' => (bool) ($opts['whole_word'] ?? true),
        ];
    }

    /**
     * Determine if a single term matches the text with given options.
     */
    private function termMatches(string $text, string $term, array $options): bool
    {
        $caseSensitive = (bool) ($options['case_sensitive'] ?? false);
        $wholeWord = (bool) ($options['whole_word'] ?? true);

        $patternBody = preg_quote($term, '/');
        $flags = $caseSensitive ? 'u' : 'iu';

        if ($wholeWord) {
            // Treat spaces AND underscores in phrases as interchangeable separators.
            // e.g. "green eyes" <=> "green_eyes" and "red_car" <=> "red car".
            $patternInner = preg_replace('/(?:\s|_)+/u', '(?:\\s|_)+', $patternBody);

            // Require non-letter/digit boundaries around the entire term/phrase.
            // Underscore should be treated as a separator (i.e., a boundary), not a word character.
            $pattern = '/(?:^|[^\p{L}\p{N}])'.$patternInner.'(?:$|[^\p{L}\p{N}])/'.$flags;
        } else {
            $pattern = '/'.$patternBody.'/'.$flags;
        }

        return preg_match($pattern, $text) === 1;
    }
}
