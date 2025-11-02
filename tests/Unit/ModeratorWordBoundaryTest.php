<?php

use App\Services\Moderation\Moderator;

it('matches whole word variants and avoids substrings', function () {
    $moderator = new Moderator;

    $rule = [
        'op' => 'any',
        'terms' => ['car'],
        'options' => ['whole_word' => true],
    ];

    $moderator->loadRules($rule);

    // Should match
    expect($moderator->check('(car)'))->toBeTrue();
    expect($moderator->check('red_car'))->toBeTrue();
    expect($moderator->check('red Car'))->toBeTrue();
    expect($moderator->check('car'))->toBeTrue();

    // Should not match substrings or other words containing the sequence
    expect($moderator->check('nascar'))->toBeFalse();
    expect($moderator->check('Jim Carrey'))
        ->toBeFalse();
});
