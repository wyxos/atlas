<?php

use App\Services\Local\LocalFetchParams;

it('returns empty response flag for invalid reaction types filter', function () {
    $context = LocalFetchParams::normalize([
        'reaction_mode' => 'types',
        'reaction' => ['not-a-real-type'],
    ]);

    expect($context['shouldReturnEmpty'])->toBeTrue()
        ->and($context['reactionTypes'])->toBe([]);
});

it('does not keep removed blacklist classification parameters', function () {
    $context = LocalFetchParams::normalize([
        'reaction_mode' => 'types',
        'reaction' => ['funny'],
        'blacklisted' => 'yes',
        'blacklist_type' => 'auto',
    ]);

    expect($context)->not->toHaveKey('moderationUnion')
        ->and($context['params'])->not->toHaveKey('moderation_union');
});

it('does not default max_previewed_count for moderated views when not explicitly provided', function () {
    $context = LocalFetchParams::normalize([
        'reaction_mode' => 'types',
        'reaction' => ['funny'],
    ]);

    expect($context['maxPreviewed'])->toBeNull()
        ->and($context['params']['max_previewed_count'])->toBeNull();
});

it('keeps explicit max_previewed_count untouched', function () {
    $context = LocalFetchParams::normalize([
        'reaction_mode' => 'types',
        'reaction' => ['funny'],
        'max_previewed_count' => 0,
    ]);

    expect($context['maxPreviewed'])->toBe(0);
});

it('normalizes file types to all when empty or invalid', function () {
    $context = LocalFetchParams::normalize([
        'file_type' => ['invalid-type'],
    ]);

    expect($context['fileTypes'])->toBe(['all']);
});
