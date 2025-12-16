<?php

use App\Models\ModerationRule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('can create a moderation rule', function () {
    $rule = ModerationRule::factory()->create([
        'name' => 'Test Rule',
        'op' => 'any',
        'terms' => ['test', 'example'],
    ]);

    expect($rule->name)->toBe('Test Rule')
        ->and($rule->op)->toBe('any')
        ->and($rule->terms)->toBe(['test', 'example'])
        ->and($rule->active)->toBeTrue()
        ->and($rule->nsfw)->toBeFalse();
});

test('can create an active rule', function () {
    $rule = ModerationRule::factory()->create(['active' => true]);

    expect($rule->active)->toBeTrue();
});

test('can create an inactive rule', function () {
    $rule = ModerationRule::factory()->create(['active' => false]);

    expect($rule->active)->toBeFalse();
});

test('can create an NSFW rule', function () {
    $rule = ModerationRule::factory()->nsfw()->create();

    expect($rule->nsfw)->toBeTrue();
});

test('can create an SFW rule', function () {
    $rule = ModerationRule::factory()->sfw()->create();

    expect($rule->nsfw)->toBeFalse();
});

test('can create a rule with any operation', function () {
    $rule = ModerationRule::factory()->any(['car', 'vehicle'])->create();

    expect($rule->op)->toBe('any')
        ->and($rule->terms)->toBe(['car', 'vehicle'])
        ->and($rule->children)->toBeNull();
});

test('can create a rule with all operation', function () {
    $rule = ModerationRule::factory()->all(['red', 'fast', 'car'])->create();

    expect($rule->op)->toBe('all')
        ->and($rule->terms)->toBe(['red', 'fast', 'car'])
        ->and($rule->children)->toBeNull();
});

test('can create a rule with not_any operation', function () {
    $rule = ModerationRule::factory()->notAny(['spam', 'advertisement'])->create();

    expect($rule->op)->toBe('not_any')
        ->and($rule->terms)->toBe(['spam', 'advertisement'])
        ->and($rule->children)->toBeNull();
});

test('can create a rule with at_least operation', function () {
    $rule = ModerationRule::factory()->atLeast(2, ['term1', 'term2', 'term3'])->create();

    expect($rule->op)->toBe('at_least')
        ->and($rule->min)->toBe(2)
        ->and($rule->terms)->toBe(['term1', 'term2', 'term3'])
        ->and($rule->children)->toBeNull();
});

test('can create a rule with and operation', function () {
    $children = [
        ['op' => 'any', 'terms' => ['car']],
        ['op' => 'any', 'terms' => ['red']],
    ];
    $rule = ModerationRule::factory()->and($children)->create();

    expect($rule->op)->toBe('and')
        ->and($rule->terms)->toBeNull()
        ->and($rule->children)->toBe($children);
});

test('can create a rule with or operation', function () {
    $children = [
        ['op' => 'any', 'terms' => ['car']],
        ['op' => 'any', 'terms' => ['truck']],
    ];
    $rule = ModerationRule::factory()->or($children)->create();

    expect($rule->op)->toBe('or')
        ->and($rule->terms)->toBeNull()
        ->and($rule->children)->toBe($children);
});

test('toNode returns correct node structure', function () {
    $rule = ModerationRule::factory()->any(['test', 'example'])->create([
        'options' => ['case_sensitive' => false, 'whole_word' => true],
    ]);

    $node = $rule->toNode();

    expect($node)->toHaveKeys(['op', 'terms', 'options'])
        ->and($node['op'])->toBe('any')
        ->and($node['terms'])->toBe(['test', 'example'])
        ->and($node['options'])->toBe(['case_sensitive' => false, 'whole_word' => true]);
});

test('toNode filters out null and empty array values', function () {
    $rule = ModerationRule::factory()->any(['test'])->create([
        'min' => null,
        'options' => null,
        'children' => null,
    ]);

    $node = $rule->toNode();

    expect($node)->not->toHaveKey('min')
        ->and($node)->not->toHaveKey('options')
        ->and($node)->not->toHaveKey('children');
});

test('toNode includes min when set', function () {
    $rule = ModerationRule::factory()->atLeast(2, ['term1', 'term2'])->create();

    $node = $rule->toNode();

    expect($node)->toHaveKey('min')
        ->and($node['min'])->toBe(2);
});

test('toNode includes children when set', function () {
    $children = [
        ['op' => 'any', 'terms' => ['car']],
    ];
    $rule = ModerationRule::factory()->and($children)->create();

    $node = $rule->toNode();

    expect($node)->toHaveKey('children')
        ->and($node['children'])->toBe($children);
});

test('can update a moderation rule', function () {
    $rule = ModerationRule::factory()->create([
        'name' => 'Original Name',
        'active' => true,
    ]);

    $rule->update([
        'name' => 'Updated Name',
        'active' => false,
    ]);

    expect($rule->fresh()->name)->toBe('Updated Name')
        ->and($rule->fresh()->active)->toBeFalse();
});

test('can delete a moderation rule', function () {
    $rule = ModerationRule::factory()->create();

    $rule->delete();

    expect(ModerationRule::find($rule->id))->toBeNull();
});

