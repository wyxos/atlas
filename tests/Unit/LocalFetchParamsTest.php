<?php

use App\Services\Local\LocalFetchParams;
use App\Services\LocalService;

it('returns empty response flag for invalid reaction types filter', function () {
    $context = LocalFetchParams::normalize([
        'reaction_mode' => 'types',
        'reaction' => ['not-a-real-type'],
    ]);

    expect($context['shouldReturnEmpty'])->toBeTrue()
        ->and($context['reactionTypes'])->toBe([]);
});

it('normalizes legacy disliked and blacklisted auto view to moderation union', function () {
    $context = LocalFetchParams::normalize([
        'reaction_mode' => 'types',
        'reaction' => ['dislike'],
        'blacklisted' => 'yes',
        'blacklist_type' => 'auto',
    ]);

    expect($context['moderationUnion'])->toBe(LocalService::MODERATION_UNION_AUTO_DISLIKED_OR_BLACKLISTED_AUTO)
        ->and($context['params']['moderation_union'])->toBe(LocalService::MODERATION_UNION_AUTO_DISLIKED_OR_BLACKLISTED_AUTO);
});

it('defaults max_previewed_count to 2 for moderated views when not explicitly provided', function () {
    $context = LocalFetchParams::normalize([
        'reaction_mode' => 'types',
        'reaction' => ['dislike'],
    ]);

    expect($context['maxPreviewed'])->toBe(2)
        ->and($context['params']['max_previewed_count'])->toBe(2);
});

it('keeps explicit max_previewed_count untouched', function () {
    $context = LocalFetchParams::normalize([
        'reaction_mode' => 'types',
        'reaction' => ['dislike'],
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
