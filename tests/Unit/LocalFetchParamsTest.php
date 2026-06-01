<?php

use App\Services\FilePreviewService;
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

it('does not default min_previewed_count when not explicitly provided', function () {
    $context = LocalFetchParams::normalize([
        'reaction_mode' => 'types',
        'reaction' => ['funny'],
    ]);

    expect($context['minPreviewed'])->toBeNull()
        ->and($context['params']['min_previewed_count'])->toBeNull();
});

it('keeps explicit max_previewed_count untouched', function () {
    $context = LocalFetchParams::normalize([
        'reaction_mode' => 'types',
        'reaction' => ['funny'],
        'max_previewed_count' => 0,
    ]);

    expect($context['maxPreviewed'])->toBe(0);
});

it('keeps explicit min_previewed_count untouched', function () {
    $context = LocalFetchParams::normalize([
        'reaction_mode' => 'any',
        'blacklisted' => 'yes',
        'min_previewed_count' => FilePreviewService::FEED_REMOVED_PREVIEW_COUNT,
    ]);

    expect($context['minPreviewed'])->toBe(FilePreviewService::FEED_REMOVED_PREVIEW_COUNT)
        ->and($context['params']['min_previewed_count'])->toBe(FilePreviewService::FEED_REMOVED_PREVIEW_COUNT);
});

it('defaults not found filtering to no and keeps explicit values', function () {
    $defaultContext = LocalFetchParams::normalize([]);
    $notFoundContext = LocalFetchParams::normalize([
        'not_found' => 'yes',
    ]);

    expect($defaultContext['notFound'])->toBe('no')
        ->and($notFoundContext['notFound'])->toBe('yes');
});

it('normalizes imported-only local filtering', function () {
    $defaultContext = LocalFetchParams::normalize([]);
    $importedContext = LocalFetchParams::normalize([
        'imported' => 'yes',
    ]);

    expect($defaultContext['imported'])->toBe('any')
        ->and($importedContext['imported'])->toBe('yes');
});

it('normalizes file types to all when empty or invalid', function () {
    $context = LocalFetchParams::normalize([
        'file_type' => ['invalid-type'],
    ]);

    expect($context['fileTypes'])->toBe(['all']);
});

it('normalizes multiple local sources and keeps them in params', function () {
    $context = LocalFetchParams::normalize([
        'source' => ['CivitAI', 'Wallhaven', 'CivitAI'],
    ]);

    expect($context['source'])->toBe(['CivitAI', 'Wallhaven'])
        ->and($context['params']['source'])->toBe(['CivitAI', 'Wallhaven']);
});

it('normalizes local date range filters', function () {
    $timezone = new DateTimeZone(date_default_timezone_get() ?: 'UTC');

    $context = LocalFetchParams::normalize([
        'date_from' => '2026-05-01',
        'date_to' => '2026-05-30',
        'downloaded_at_from' => '2026-04-01',
        'downloaded_at_to' => '2026-04-30',
        'blacklisted_at_from' => '2026-03-01',
        'blacklisted_at_to' => '2026-03-31',
    ]);

    expect($context['createdFrom'])->toBe((new DateTimeImmutable('2026-05-01 00:00:00', $timezone))->getTimestamp())
        ->and($context['createdTo'])->toBe((new DateTimeImmutable('2026-05-30 23:59:59', $timezone))->getTimestamp())
        ->and($context['downloadedFrom'])->toBe((new DateTimeImmutable('2026-04-01 00:00:00', $timezone))->getTimestamp())
        ->and($context['downloadedTo'])->toBe((new DateTimeImmutable('2026-04-30 23:59:59', $timezone))->getTimestamp())
        ->and($context['blacklistedFrom'])->toBe((new DateTimeImmutable('2026-03-01 00:00:00', $timezone))->getTimestamp())
        ->and($context['blacklistedTo'])->toBe((new DateTimeImmutable('2026-03-31 23:59:59', $timezone))->getTimestamp())
        ->and($context['params']['date_from'])->toBe('2026-05-01')
        ->and($context['params']['date_to'])->toBe('2026-05-30')
        ->and($context['params']['downloaded_at_from'])->toBe('2026-04-01')
        ->and($context['params']['downloaded_at_to'])->toBe('2026-04-30')
        ->and($context['params']['blacklisted_at_from'])->toBe('2026-03-01')
        ->and($context['params']['blacklisted_at_to'])->toBe('2026-03-31');
});

it('drops invalid local date range filters', function () {
    $context = LocalFetchParams::normalize([
        'date_from' => 'not-a-date',
        'date_to' => '2026-99-99',
        'downloaded_at_from' => '2026-02-30',
        'downloaded_at_to' => 'latest',
        'blacklisted_at_from' => '',
        'blacklisted_at_to' => [],
    ]);

    expect($context['createdFrom'])->toBeNull()
        ->and($context['createdTo'])->toBeNull()
        ->and($context['downloadedFrom'])->toBeNull()
        ->and($context['downloadedTo'])->toBeNull()
        ->and($context['blacklistedFrom'])->toBeNull()
        ->and($context['blacklistedTo'])->toBeNull()
        ->and($context['params'])->not->toHaveKey('date_from')
        ->and($context['params'])->not->toHaveKey('date_to')
        ->and($context['params'])->not->toHaveKey('downloaded_at_from')
        ->and($context['params'])->not->toHaveKey('downloaded_at_to')
        ->and($context['params'])->not->toHaveKey('blacklisted_at_from')
        ->and($context['params'])->not->toHaveKey('blacklisted_at_to');
});
