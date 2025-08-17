<?php

use App\Models\ModerationRule;
use App\Support\ContentModerator;

it('loads active rules from DB and matches correctly (contains + unless)', function () {
    // Active rule that should match unless exception term present
    ModerationRule::create([
        'type' => 'contains',
        'terms' => ['buzz'],
        'unless' => ['hero'],
        'match' => 'any',
        'action' => 'block',
        'active' => true,
    ]);

    // Inactive rule should be ignored
    ModerationRule::create([
        'type' => 'contains',
        'terms' => ['ignoreme'],
        'match' => 'any',
        'action' => 'block',
        'active' => false,
    ]);

    $moderator = ContentModerator::fromDatabase();

    expect($moderator->matches('buzz the bee'))
        ->toHaveCount(1)
        ->and($moderator->matches('hero buzz'))
        ->toHaveCount(0);
});

it('matches contains-combo rules from DB and returns matched rule payloads', function () {
    ModerationRule::create([
        'type' => 'contains-combo',
        'terms' => ['car'],
        'with_terms' => ['fuel', 'fire'],
        'action' => 'block',
        'active' => true,
    ]);

    $moderator = ContentModerator::fromDatabase();

    $hits = $moderator->matches('the car uses fuel');
    expect($hits)->toHaveCount(1)
        ->and($hits[0])
        ->toHaveKeys(['type','terms','action','with','id','name']);

    expect($moderator->matches('just a car'))->toHaveCount(0);
});
