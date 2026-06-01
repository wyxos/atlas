<?php

use App\Services\Library\LibraryTypesenseCompiler;
use App\Services\Library\LibraryTypesenseNames;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config()->set('library.typesense.files_alias', 'atlas_local_library_files');
    config()->set('library.typesense.reactions_alias', 'atlas_local_library_reactions');
});

test('compiler builds default browse query', function () {
    $compiler = app(LibraryTypesenseCompiler::class);

    $compiled = $compiler->compile([
        'page' => 1,
        'limit' => 20,
        'sort' => 'stored_at',
        'fileTypes' => ['all'],
        'reactionMode' => 'any',
        'reactionTypes' => null,
        'downloaded' => 'any',
        'blacklisted' => 'any',
        'autoBlacklisted' => 'any',
    ], 7);

    expect($compiled['mode'])->toBe('files')
        ->and($compiled['collection'])->toBe(app(LibraryTypesenseNames::class)->filesAlias())
        ->and($compiled['options']['filter_by'])->toBe('not_found:=false')
        ->and($compiled['options']['sort_by'])->toBe('stored_at:desc,updated_at:desc,sort_id:desc');
});

test('compiler builds source downloaded file-type and random filters', function () {
    $compiler = app(LibraryTypesenseCompiler::class);

    $compiled = $compiler->compile([
        'page' => 3,
        'limit' => 50,
        'source' => 'Wallhaven',
        'downloaded' => 'no',
        'sort' => 'random',
        'seed' => 123,
        'fileTypes' => ['video'],
        'imported' => 'yes',
        'maxPreviewed' => 2,
        'reactionMode' => 'any',
        'reactionTypes' => null,
        'blacklisted' => 'any',
        'autoBlacklisted' => 'any',
    ], 7);

    expect($compiled['options']['filter_by'])->toContain('source:=`Wallhaven`')
        ->and($compiled['options']['filter_by'])->toContain('not_found:=false')
        ->and($compiled['options']['filter_by'])->toContain('downloaded:=false')
        ->and($compiled['options']['filter_by'])->toContain('imported_at:>0')
        ->and($compiled['options']['filter_by'])->toContain('previewed_count:<=2')
        ->and($compiled['options']['filter_by'])->toContain('mime_group:=[`video`]')
        ->and($compiled['options']['sort_by'])->toBe('_rand(123):desc,sort_id:desc');
});

test('compiler builds multiple source filter', function () {
    $compiler = app(LibraryTypesenseCompiler::class);

    $filter = $compiler->compileFileFilter([
        'source' => ['CivitAI', 'Wallhaven'],
        'downloaded' => 'any',
        'blacklisted' => 'any',
        'autoBlacklisted' => 'any',
        'reactionMode' => 'any',
        'reactionTypes' => null,
        'fileTypes' => ['all'],
    ], 11);

    expect($filter)->toContain('source:=[`CivitAI`, `Wallhaven`]')
        ->and($filter)->toContain('not_found:=false');
});

test('compiler builds date range filters', function () {
    $compiler = app(LibraryTypesenseCompiler::class);
    $timezone = new DateTimeZone('UTC');
    $createdFrom = (new DateTimeImmutable('2026-05-01 00:00:00', $timezone))->getTimestamp();
    $createdTo = (new DateTimeImmutable('2026-05-30 23:59:59', $timezone))->getTimestamp();
    $downloadedFrom = (new DateTimeImmutable('2026-04-01 00:00:00', $timezone))->getTimestamp();
    $downloadedTo = (new DateTimeImmutable('2026-04-30 23:59:59', $timezone))->getTimestamp();
    $blacklistedFrom = (new DateTimeImmutable('2026-03-01 00:00:00', $timezone))->getTimestamp();
    $blacklistedTo = (new DateTimeImmutable('2026-03-31 23:59:59', $timezone))->getTimestamp();

    $filter = $compiler->compileFileFilter([
        'createdFrom' => $createdFrom,
        'createdTo' => $createdTo,
        'downloadedFrom' => $downloadedFrom,
        'downloadedTo' => $downloadedTo,
        'blacklistedFrom' => $blacklistedFrom,
        'blacklistedTo' => $blacklistedTo,
        'downloaded' => 'any',
        'blacklisted' => 'any',
        'autoBlacklisted' => 'any',
        'reactionMode' => 'any',
        'reactionTypes' => null,
        'fileTypes' => ['all'],
    ], 11);

    expect($filter)->toContain('created_at:>='.$createdFrom)
        ->and($filter)->toContain('created_at:<='.$createdTo)
        ->and($filter)->toContain('downloaded_at:>='.$downloadedFrom)
        ->and($filter)->toContain('downloaded_at:<='.$downloadedTo)
        ->and($filter)->toContain('blacklisted_at:>='.$blacklistedFrom)
        ->and($filter)->toContain('blacklisted_at:<='.$blacklistedTo)
        ->and($filter)->toContain('not_found:=false');
});

test('compiler ignores source filters when all is selected with other sources', function () {
    $compiler = app(LibraryTypesenseCompiler::class);

    $filter = $compiler->compileFileFilter([
        'source' => ['all', 'CivitAI'],
        'downloaded' => 'any',
        'blacklisted' => 'any',
        'autoBlacklisted' => 'any',
        'reactionMode' => 'any',
        'reactionTypes' => null,
        'fileTypes' => ['all'],
    ], 11);

    expect($filter)->not->toContain('source:=')
        ->and($filter)->toContain('not_found:=false');
});

test('compiler builds blacklisted and Auto blacklisted filters', function () {
    $compiler = app(LibraryTypesenseCompiler::class);

    $filter = $compiler->compileFileFilter([
        'blacklisted' => 'yes',
        'autoBlacklisted' => 'yes',
        'reactionMode' => 'any',
        'reactionTypes' => null,
        'fileTypes' => ['all'],
        'downloaded' => 'any',
    ], 11);

    expect($filter)->toContain('blacklisted:=true')
        ->and($filter)->toContain('not_found:=false')
        ->and($filter)->toContain('auto_blacklisted:=true');
});

test('compiler builds not found filters', function () {
    $compiler = app(LibraryTypesenseCompiler::class);

    $notFound = $compiler->compileFileFilter([
        'notFound' => 'yes',
        'blacklisted' => 'no',
        'autoBlacklisted' => 'any',
        'reactionMode' => 'any',
        'reactionTypes' => null,
        'fileTypes' => ['all'],
        'downloaded' => 'any',
    ], 11);
    $any = $compiler->compileFileFilter([
        'notFound' => 'any',
        'blacklisted' => 'no',
        'autoBlacklisted' => 'any',
        'reactionMode' => 'any',
        'reactionTypes' => null,
        'fileTypes' => ['all'],
        'downloaded' => 'any',
    ], 11);

    expect($notFound)->toContain('not_found:=true')
        ->and($notFound)->toContain('blacklisted:=false')
        ->and($any)->not->toContain('not_found:=')
        ->and($any)->toContain('blacklisted:=false');
});

test('compiler builds reacted types and unreacted filters', function () {
    $compiler = app(LibraryTypesenseCompiler::class);

    $reacted = $compiler->compileFileFilter([
        'reactionMode' => 'reacted',
        'reactionTypes' => null,
        'fileTypes' => ['all'],
        'downloaded' => 'any',
        'blacklisted' => 'any',
        'autoBlacklisted' => 'any',
    ], 9);

    $typed = $compiler->compileFileFilter([
        'reactionMode' => 'types',
        'reactionTypes' => ['like', 'funny'],
        'fileTypes' => ['all'],
        'downloaded' => 'any',
        'blacklisted' => 'any',
        'autoBlacklisted' => 'any',
    ], 9);

    $unreacted = $compiler->compileFileFilter([
        'reactionMode' => 'unreacted',
        'reactionTypes' => null,
        'fileTypes' => ['all'],
        'downloaded' => 'any',
        'blacklisted' => 'any',
        'autoBlacklisted' => 'any',
    ], 9);

    expect($reacted)->toContain('love_user_ids:=[9]')
        ->and($reacted)->toContain('not_found:=false')
        ->and($reacted)->toContain('like_user_ids:=[9]')
        ->and($reacted)->toContain('funny_user_ids:=[9]')
        ->and($typed)->toContain('not_found:=false')
        ->and($typed)->toContain('like_user_ids:=[9]')
        ->and($typed)->toContain('funny_user_ids:=[9]')
        ->and($unreacted)->toContain('not_found:=false')
        ->and($unreacted)->toContain('reacted_user_ids:!=[9]');
});

test('compiler builds reaction timestamp queries for descending and ascending sorts', function () {
    $compiler = app(LibraryTypesenseCompiler::class);

    $desc = $compiler->compile([
        'page' => 2,
        'limit' => 25,
        'sort' => 'reaction_at',
        'source' => 'CivitAI',
        'downloaded' => 'yes',
        'blacklisted' => 'no',
        'maxPreviewed' => 1,
        'fileTypes' => ['image', 'video'],
        'reactionMode' => 'reacted',
        'reactionTypes' => null,
        'autoBlacklisted' => 'no',
        'allTypes' => ['love', 'like', 'funny'],
    ], 5, 'atlas_local_library_files__v20260331_000000');

    $asc = $compiler->compile([
        'page' => 1,
        'limit' => 25,
        'sort' => 'reaction_at_asc',
        'source' => 'CivitAI',
        'downloaded' => 'yes',
        'blacklisted' => 'no',
        'maxPreviewed' => 1,
        'fileTypes' => ['image', 'video'],
        'reactionMode' => 'types',
        'reactionTypes' => ['funny'],
        'autoBlacklisted' => 'no',
        'allTypes' => ['love', 'like', 'funny'],
    ], 5, 'atlas_local_library_files__v20260331_000000');
    $notFoundReacted = $compiler->compile([
        'page' => 1,
        'limit' => 25,
        'sort' => 'reaction_at',
        'notFound' => 'yes',
        'blacklisted' => 'no',
        'fileTypes' => ['all'],
        'downloaded' => 'any',
        'reactionMode' => 'reacted',
        'reactionTypes' => null,
        'autoBlacklisted' => 'any',
        'allTypes' => ['love', 'like', 'funny'],
    ], 5, 'atlas_local_library_files__v20260331_000000');

    expect($desc['mode'])->toBe('reactions')
        ->and($desc['collection'])->toBe(app(LibraryTypesenseNames::class)->reactionsAlias())
        ->and($desc['options']['filter_by'])->toContain('user_id:=5')
        ->and($desc['options']['filter_by'])->toContain('type:=[`love`, `like`, `funny`]')
        ->and($desc['options']['filter_by'])->toContain('$atlas_local_library_files__v20260331_000000(')
        ->and($desc['options']['filter_by'])->toContain('not_found:=false')
        ->and($desc['options']['filter_by'])->toContain('source:=`CivitAI`')
        ->and($desc['options']['filter_by'])->toContain('downloaded:=true')
        ->and($desc['options']['filter_by'])->toContain('blacklisted:=false')
        ->and($desc['options']['filter_by'])->toContain('previewed_count:<=1')
        ->and($desc['options']['filter_by'])->toContain('mime_group:=[`image`, `video`]')
        ->and($desc['options']['filter_by'])->toContain('auto_blacklisted:=false')
        ->and($desc['options']['sort_by'])->toBe('created_at:desc,sort_id:desc')
        ->and($asc['options']['filter_by'])->toContain('type:=[`funny`]')
        ->and($asc['options']['sort_by'])->toBe('created_at:asc,sort_id:asc')
        ->and($notFoundReacted['options']['filter_by'])->toContain('not_found:=true')
        ->and($notFoundReacted['options']['filter_by'])->toContain('blacklisted:=false');
});
