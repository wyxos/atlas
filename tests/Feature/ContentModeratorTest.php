<?php

use App\Support\ContentModerator;

beforeEach(function () {
    $this->rules = [
        ['type' => 'contains', 'terms' => ['toilet'], 'action' => 'block'],
        ['type' => 'contains', 'terms' => ['buzz'], 'unless' => ['hero'], 'action' => 'block'],
        ['type' => 'contains', 'terms' => ['pokemon', 'pokemons'], 'match' => 'any', 'action' => 'block'],
        ['type' => 'contains', 'terms' => ['forest', 'demon', 'usernamea'], 'match' => 'all', 'action' => 'block'],
        ['type' => 'contains-combo', 'terms' => ['car'], 'with' => ['fuel', 'fire', 'f1'], 'action' => 'block'],
    ];

    $this->moderator = new ContentModerator($this->rules);
});

test('blocks text containing toilet', function () {
    expect($this->moderator->shouldBlock('I saw a toilet'))->toBeTrue();
});

test('blocks buzz unless hero is present', function () {
    expect($this->moderator->shouldBlock('buzz the bee'))->toBeTrue();
    expect($this->moderator->shouldBlock('hero buzz lightyear'))->toBeFalse();
});

test('blocks any pokemon references', function () {
    expect($this->moderator->shouldBlock('i like pokemon'))->toBeTrue();
    expect($this->moderator->shouldBlock('i like pokemons'))->toBeTrue();
    expect($this->moderator->shouldBlock('this is a game'))->toBeFalse();
});

test('blocks only when all terms are present (forest, demon, usernameA)', function () {
    expect($this->moderator->shouldBlock('a forest demon with usernameA'))->toBeTrue();
    expect($this->moderator->shouldBlock('just a forest demon'))->toBeFalse();
});

test('blocks if car + any of fuel, fire, or F1 are present', function () {
    expect($this->moderator->shouldBlock('the car uses fuel'))->toBeTrue();
    expect($this->moderator->shouldBlock('car on fire'))->toBeTrue();
    expect($this->moderator->shouldBlock('car races in F1'))->toBeTrue();
    expect($this->moderator->shouldBlock('just a car'))->toBeFalse();
});

// Scenario: (worda and wordb) AND any of (wordx, wordy)
// Implemented using contains-combo with terms match 'all' and with any
test('blocks if both alpha and beta are present AND any of x or y', function () {
    $rules = [
        ['type' => 'contains-combo', 'terms' => ['alpha', 'beta'], 'match' => 'all', 'with' => ['x', 'y'], 'action' => 'block'],
    ];
    $moderator = new \App\Support\ContentModerator($rules);

    expect($moderator->shouldBlock('alpha beta with x'))
        ->toBeTrue()
        ->and($moderator->shouldBlock('alpha y'))
        ->toBeFalse() // missing beta
        ->and($moderator->shouldBlock('beta something'))
        ->toBeFalse() // missing alpha and with-term
        ->and($moderator->shouldBlock('alpha beta none'))
        ->toBeFalse(); // no with-term present
});
