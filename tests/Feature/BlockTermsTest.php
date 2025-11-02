<?php

use App\Services\Moderation\Moderator;

// BlockTermsTest — rules-driven text moderation expectations for a future Moderator class.
// Rule node shape:
// - op: 'any' | 'all' | 'not_any' | 'at_least' | 'and' | 'or'
// - terms?: string[]
// - min?: int  (required for 'at_least')
// - children?: array<RuleNode> (for 'and'/'or')
// - options?: ['case_sensitive' => bool, 'whole_word' => bool] (defaults: false, true)
//
// Expected Moderator API:
//   $moderator = new Moderator();
//   $moderator->loadRules(array $rule): void;
//   $blocked = $moderator->check(string $text): bool; // true => should be blocked

// Dataset capturing representative scenarios derived from the comments in this file.
dataset('block_rule_cases', [
    // 1) simple contains word
    'contains car' => [
        'text' => 'This is a car',
        'node' => ['op' => 'any', 'terms' => ['car']],
        'expected' => true,
    ],

    // 2) must contain all: car, red, fast
    'contains car red fast (all)' => [
        'text' => 'A red fast car zooms by',
        'node' => ['op' => 'all', 'terms' => ['car', 'red', 'fast']],
        'expected' => true,
    ],
    'missing one from all' => [
        'text' => 'A red car is slow',
        'node' => ['op' => 'all', 'terms' => ['car', 'red', 'fast']],
        'expected' => false,
    ],

    // 3) all of (car, red, fast) but NOT sun
    'all but not sun (negative)' => [
        'text' => 'red fast car by the sea',
        'node' => [
            'op' => 'and',
            'children' => [
                ['op' => 'all', 'terms' => ['car', 'red', 'fast']],
                ['op' => 'not_any', 'terms' => ['sun']],
            ],
        ],
        'expected' => true,
    ],
    'all but contains forbidden sun' => [
        'text' => 'red fast car under the sun',
        'node' => [
            'op' => 'and',
            'children' => [
                ['op' => 'all', 'terms' => ['car', 'red', 'fast']],
                ['op' => 'not_any', 'terms' => ['sun']],
            ],
        ],
        'expected' => false,
    ],

    // 4) (car and red) and (sun and sea)
    'two all groups in and' => [
        'text' => 'red car by the sun and the sea',
        'node' => [
            'op' => 'and',
            'children' => [
                ['op' => 'all', 'terms' => ['car', 'red']],
                ['op' => 'all', 'terms' => ['sun', 'sea']],
            ],
        ],
        'expected' => true,
    ],

    // 5) either car, red, fast
    'any of car red fast' => [
        'text' => 'The fast lane',
        'node' => ['op' => 'any', 'terms' => ['car', 'red', 'fast']],
        'expected' => true,
    ],

    // 6) one of (car, red, fast) AND all of (sun, sea)
    'any plus all' => [
        'text' => 'fast fun under the sun at the sea',
        'node' => [
            'op' => 'and',
            'children' => [
                ['op' => 'any', 'terms' => ['car', 'red', 'fast']],
                ['op' => 'all', 'terms' => ['sun', 'sea']],
            ],
        ],
        'expected' => true,
    ],
    'any plus all - missing one all term' => [
        'text' => 'fast fun under the sun',
        'node' => [
            'op' => 'and',
            'children' => [
                ['op' => 'any', 'terms' => ['car', 'red', 'fast']],
                ['op' => 'all', 'terms' => ['sun', 'sea']],
            ],
        ],
        'expected' => false,
    ],

    // 7) has at least three of (...) AND contains any of (...)
    'at least three plus any' => [
        'text' => 'red fast car near a garage by the sea',
        'node' => [
            'op' => 'and',
            'children' => [
                ['op' => 'at_least', 'min' => 3, 'terms' => ['car', 'red', 'fast', 'sun', 'sea', 'beach']],
                ['op' => 'any', 'terms' => ['demo', 'garage', 'house']],
            ],
        ],
        'expected' => true,
    ],
    'at least three - only two present' => [
        'text' => 'red car parked at home',
        'node' => [
            'op' => 'and',
            'children' => [
                ['op' => 'at_least', 'min' => 3, 'terms' => ['car', 'red', 'fast', 'sun', 'sea', 'beach']],
                ['op' => 'any', 'terms' => ['demo', 'garage', 'house']],
            ],
        ],
        'expected' => false,
    ],

    // 8) word boundary: 'car' should not match 'scar'
    'word boundary' => [
        'text' => 'a scar on the arm',
        'node' => ['op' => 'any', 'terms' => ['car'], 'options' => ['whole_word' => true]],
        'expected' => false,
    ],
    // New: ensure 'nascar' does not match 'car' when whole_word=true
    'word boundary nascar' => [
        'text' => 'Watching nascar tonight',
        'node' => ['op' => 'any', 'terms' => ['car'], 'options' => ['whole_word' => true]],
        'expected' => false,
    ],

    // 9) case-insensitive by default
    'case-insensitive' => [
        'text' => 'Car and Truck',
        'node' => ['op' => 'any', 'terms' => ['car']],
        'expected' => true,
    ],

    // 10) punctuation tolerant
    'punctuation tolerant' => [
        'text' => 'red, fast, car!',
        'node' => ['op' => 'all', 'terms' => ['car', 'red', 'fast']],
        'expected' => true,
    ],

    // 11) phrase matching
    'phrase match' => [
        'text' => 'lovely red car on display',
        'node' => ['op' => 'any', 'terms' => ['red car']],
        'expected' => true,
    ],
    'phrase match (case and underscore tolerant for spaces)' => [
        'text' => 'She has Green eyes and her friend has green_eyes',
        'node' => ['op' => 'any', 'terms' => ['green eyes']],
        'expected' => true,
    ],

    // Additional phrase/underscore interoperability tests
    'underscore in rule matches spaced phrase (case-insensitive)' => [
        'text' => 'A lovely Red car on display',
        'node' => ['op' => 'any', 'terms' => ['red_car']],
        'expected' => true,
    ],
    'underscore in rule matches spaced phrase with parentheses' => [
        'text' => 'Look at (Red car) now',
        'node' => ['op' => 'any', 'terms' => ['red_car']],
        'expected' => true,
    ],
    'space in rule matches underscored text' => [
        'text' => 'Spotted a red_car nearby',
        'node' => ['op' => 'any', 'terms' => ['red car']],
        'expected' => true,
    ],
    'underscore in rule matches spaced phrase with parentheses (green eyes)' => [
        'text' => 'They whispered about (green eyes) in the hall',
        'node' => ['op' => 'any', 'terms' => ['green_eyes']],
        'expected' => true,
    ],

    // Negative: substring vs whole-word
    'substring not allowed when whole_word true' => [
        'text' => 'carpet is soft',
        'node' => ['op' => 'any', 'terms' => ['car'], 'options' => ['whole_word' => true]],
        'expected' => false,
    ],
    'substring allowed when whole_word false' => [
        'text' => 'carpet is soft',
        'node' => ['op' => 'any', 'terms' => ['car'], 'options' => ['whole_word' => false]],
        'expected' => true,
    ],
    // New: explicitly show that 'nascar' would match when whole_word=false
    'substring allowed when whole_word false (nascar)' => [
        'text' => 'Watching nascar tonight',
        'node' => ['op' => 'any', 'terms' => ['car'], 'options' => ['whole_word' => false]],
        'expected' => true,
    ],

    // 1) negative for simple contains word
    'does not contain car' => [
        'text' => 'This is a bike',
        'node' => ['op' => 'any', 'terms' => ['car']],
        'expected' => false,
    ],

    // 4) negative for two all groups in and
    'two all groups in and - missing from second group' => [
        'text' => 'red car by the sun',
        'node' => [
            'op' => 'and',
            'children' => [
                ['op' => 'all', 'terms' => ['car', 'red']],
                ['op' => 'all', 'terms' => ['sun', 'sea']],
            ],
        ],
        'expected' => false,
    ],

    // 5) negative for any of car/red/fast
    'any of car red fast - none present' => [
        'text' => 'The slow lane',
        'node' => ['op' => 'any', 'terms' => ['car', 'red', 'fast']],
        'expected' => false,
    ],

    // 9) case sensitivity explicit positives/negatives
    'case-sensitive true mismatch' => [
        'text' => 'Car and Truck',
        'node' => ['op' => 'any', 'terms' => ['car'], 'options' => ['case_sensitive' => true]],
        'expected' => false,
    ],
    'case-sensitive true exact match' => [
        'text' => 'car and Truck',
        'node' => ['op' => 'any', 'terms' => ['car'], 'options' => ['case_sensitive' => true]],
        'expected' => true,
    ],

    // 10) punctuation tolerant - negative substring-only
    'punctuation tolerant - substrings should not match' => [
        'text' => 'carpet, redden, fasten',
        'node' => ['op' => 'all', 'terms' => ['car', 'red', 'fast']],
        'expected' => false,
    ],

    // 11) phrase matching - negative hyphenated mismatch
    'phrase mismatch with hyphen' => [
        'text' => 'lovely red-car on display',
        'node' => ['op' => 'any', 'terms' => ['red car']],
        'expected' => false,
    ],

    // explicit OR operator coverage
    'or between groups (positive)' => [
        'text' => 'red car spotted',
        'node' => [
            'op' => 'or',
            'children' => [
                ['op' => 'all', 'terms' => ['car', 'red']],
                ['op' => 'all', 'terms' => ['sun', 'sea']],
            ],
        ],
        'expected' => true,
    ],
    'or between groups (negative)' => [
        'text' => 'green boat on the lake',
        'node' => [
            'op' => 'or',
            'children' => [
                ['op' => 'all', 'terms' => ['car', 'red']],
                ['op' => 'all', 'terms' => ['sun', 'sea']],
            ],
        ],
        'expected' => false,
    ],

    // three any-of groups combined with AND
    'three any groups in and (positive)' => [
        'text' => 'a male wearing a red cap',
        'node' => [
            'op' => 'and',
            'children' => [
                ['op' => 'any', 'terms' => ['man', 'boy', 'male', 'guy', '1guy']],
                ['op' => 'any', 'terms' => ['hat', 'cap', 'helmet']],
                ['op' => 'any', 'terms' => ['red', 'santa', 'beard', 'green eyes', 'laughing']],
            ],
        ],
        'expected' => true,
    ],
    'three any groups in and (negative - missing group C)' => [
        'text' => 'a male wearing a cap',
        'node' => [
            'op' => 'and',
            'children' => [
                ['op' => 'any', 'terms' => ['man', 'boy', 'male', 'guy', '1guy']],
                ['op' => 'any', 'terms' => ['hat', 'cap', 'helmet']],
                ['op' => 'any', 'terms' => ['red', 'santa', 'beard', 'green eyes', 'laughing']],
            ],
        ],
        'expected' => false,
    ],

    // Additional whole-word positive/negative assertions per requirements
    'parenthesized whole word' => [
        'text' => 'Look (car) here',
        'node' => ['op' => 'any', 'terms' => ['car'], 'options' => ['whole_word' => true]],
        'expected' => true,
    ],
    'underscore separator whole word' => [
        'text' => 'Spotted a red_car nearby',
        'node' => ['op' => 'any', 'terms' => ['car'], 'options' => ['whole_word' => true]],
        'expected' => true,
    ],
    'space separator case-insensitive whole word' => [
        'text' => 'the red Car zoomed by',
        'node' => ['op' => 'any', 'terms' => ['car'], 'options' => ['whole_word' => true]],
        'expected' => true,
    ],
    'name carrey should not match car' => [
        'text' => 'Jim Carrey is funny',
        'node' => ['op' => 'any', 'terms' => ['car'], 'options' => ['whole_word' => true]],
        'expected' => false,
    ],
]);

test('Moderator evaluates block rules correctly', function (string $text, array $node, bool $expected) {
    $moderator = new Moderator;

    // Create a ModerationRule record using the factory and the provided node fields.
    $rule = \App\Models\ModerationRule::factory()->create([
        'op' => $node['op'] ?? 'any',
        'terms' => $node['terms'] ?? null,
        'min' => $node['min'] ?? null,
        'options' => $node['options'] ?? null,
        'children' => $node['children'] ?? null,
    ]);

    $moderator->loadRule($rule);

    $result = $moderator->check($text);

    expect($result)->toBe($expected);
})->with('block_rule_cases');
