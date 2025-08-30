<?php

use App\Models\ModerationRule;
use Illuminate\Foundation\Testing\RefreshDatabase;

test('can create moderation rule with contains type', function () {
    $rule = ModerationRule::create([
        'name' => 'Block profanity',
        'type' => 'contains',
        'terms' => ['badword', 'inappropriate'],
        'match' => 'any',
        'action' => 'block',
        'active' => true,
        'description' => 'Blocks inappropriate content',
    ]);

    expect($rule)->toBeInstanceOf(ModerationRule::class)
        ->and($rule->name)->toBe('Block profanity')
        ->and($rule->type)->toBe('contains')
        ->and($rule->terms)->toBe(['badword', 'inappropriate'])
        ->and($rule->match)->toBe('any')
        ->and($rule->action)->toBe('block')
        ->and($rule->active)->toBeTrue();
});

test('can create moderation rule with contains-combo type', function () {
    $rule = ModerationRule::create([
        'name' => 'Block car racing content',
        'type' => 'contains-combo',
        'terms' => ['car'],
        'with_terms' => ['fuel', 'fire', 'f1'],
        'action' => 'flag',
        'active' => true,
    ]);

    expect($rule->type)->toBe('contains-combo')
        ->and($rule->terms)->toBe(['car'])
        ->and($rule->with_terms)->toBe(['fuel', 'fire', 'f1'])
        ->and($rule->action)->toBe('flag');
});

test('can create rule with unless conditions', function () {
    $rule = ModerationRule::create([
        'type' => 'contains',
        'terms' => ['buzz'],
        'unless' => ['hero'],
        'match' => 'any',
        'action' => 'block',
        'active' => true,
    ]);

    expect($rule->unless)->toBe(['hero']);
});

test('converts to content moderator format for contains type', function () {
    $rule = ModerationRule::create([
        'type' => 'contains',
        'terms' => ['toilet'],
        'match' => 'any',
        'action' => 'block',
        'active' => true,
    ]);

    $format = $rule->toContentModeratorFormat();

    expect($format)->toBe([
        'type' => 'contains',
        'terms' => ['toilet'],
        'action' => 'block',
        'match' => 'any',
    ]);
});

test('converts to content moderator format with unless conditions', function () {
    $rule = ModerationRule::create([
        'type' => 'contains',
        'terms' => ['buzz'],
        'unless' => ['hero'],
        'match' => 'any',
        'action' => 'block',
        'active' => true,
    ]);

    $format = $rule->toContentModeratorFormat();

    expect($format)->toBe([
        'type' => 'contains',
        'terms' => ['buzz'],
        'action' => 'block',
        'match' => 'any',
        'unless' => ['hero'],
    ]);
});

test('converts to content moderator format for contains-combo type', function () {
    $rule = ModerationRule::create([
        'type' => 'contains-combo',
        'terms' => ['car'],
        'with_terms' => ['fuel', 'fire'],
        'action' => 'block',
        'active' => true,
    ]);

    $format = $rule->toContentModeratorFormat();

    expect($format)->toBe([
        'type' => 'contains-combo',
        'terms' => ['car'],
        'action' => 'block',
        'with' => ['fuel', 'fire'],
    ]);
});

test('contains-combo includes match when set (terms match mode)', function () {
    $rule = ModerationRule::create([
        'type' => 'contains-combo',
        'terms' => ['alpha','beta'],
        'with_terms' => ['x','y'],
        'match' => 'all',
        'action' => 'block',
        'active' => true,
    ]);

    $format = $rule->toContentModeratorFormat();

    expect($format)->toBe([
        'type' => 'contains-combo',
        'terms' => ['alpha','beta'],
        'action' => 'block',
        'with' => ['x','y'],
        'match' => 'all',
    ]);
});

test('factory creates valid moderation rules', function () {
    $containsRule = ModerationRule::factory()->containsType()->create();
    $comboRule = ModerationRule::factory()->containsComboType()->create();

    expect($containsRule->type)->toBe('contains')
        ->and($containsRule->terms)->toBeArray()
        ->and($containsRule->match)->toBeIn(['any', 'all']);

    expect($comboRule->type)->toBe('contains-combo')
        ->and($comboRule->terms)->toBeArray()
        ->and($comboRule->with_terms)->toBeArray();
});

test('can query active rules', function () {
    ModerationRule::factory()->active()->create();
    ModerationRule::factory()->inactive()->create();

    $activeRules = ModerationRule::where('active', true)->get();
    $inactiveRules = ModerationRule::where('active', false)->get();

    expect($activeRules)->toHaveCount(1)
        ->and($inactiveRules)->toHaveCount(1);
});
