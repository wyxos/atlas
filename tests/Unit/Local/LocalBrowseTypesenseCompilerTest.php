<?php

use App\Services\Local\LocalBrowseTypesenseCompiler;
use App\Services\Local\LocalBrowseTypesenseNames;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config()->set('local_browse.typesense.files_alias', 'atlas_local_local_browse_files');
    config()->set('local_browse.typesense.reactions_alias', 'atlas_local_local_browse_reactions');
});

test('compiler builds default browse query', function () {
    $compiler = app(LocalBrowseTypesenseCompiler::class);

    $compiled = $compiler->compile([
        'page' => 1,
        'limit' => 20,
        'sort' => 'downloaded_at',
        'fileTypes' => ['all'],
        'reactionMode' => 'any',
        'reactionTypes' => null,
        'downloaded' => 'any',
        'blacklisted' => 'any',
        'autoDisliked' => 'any',
    ], 7);

    expect($compiled['mode'])->toBe('files')
        ->and($compiled['collection'])->toBe(app(LocalBrowseTypesenseNames::class)->filesAlias())
        ->and($compiled['options']['filter_by'])->toBe('not_found:=false')
        ->and($compiled['options']['sort_by'])->toBe('downloaded_at:desc,updated_at:desc,sort_id:desc');
});

test('compiler builds source downloaded file-type and random filters', function () {
    $compiler = app(LocalBrowseTypesenseCompiler::class);

    $compiled = $compiler->compile([
        'page' => 3,
        'limit' => 50,
        'source' => 'Wallhaven',
        'downloaded' => 'no',
        'sort' => 'random',
        'seed' => 123,
        'fileTypes' => ['video'],
        'maxPreviewed' => 2,
        'reactionMode' => 'any',
        'reactionTypes' => null,
        'blacklisted' => 'any',
        'autoDisliked' => 'any',
    ], 7);

    expect($compiled['options']['filter_by'])->toContain('source:=`Wallhaven`')
        ->and($compiled['options']['filter_by'])->toContain('not_found:=false')
        ->and($compiled['options']['filter_by'])->toContain('downloaded:=false')
        ->and($compiled['options']['filter_by'])->toContain('previewed_count:<=2')
        ->and($compiled['options']['filter_by'])->toContain('mime_group:=[`video`]')
        ->and($compiled['options']['sort_by'])->toBe('_rand(123):desc,sort_id:desc');
});

test('compiler builds blacklisted and auto disliked filters', function () {
    $compiler = app(LocalBrowseTypesenseCompiler::class);

    $filter = $compiler->compileFileFilter([
        'blacklisted' => 'yes',
        'autoDisliked' => 'yes',
        'reactionMode' => 'any',
        'reactionTypes' => null,
        'fileTypes' => ['all'],
        'downloaded' => 'any',
    ], 11);

    expect($filter)->toContain('blacklisted:=true')
        ->and($filter)->toContain('not_found:=false')
        ->and($filter)->toContain('auto_disliked:=true');
});

test('compiler builds reacted types and unreacted filters', function () {
    $compiler = app(LocalBrowseTypesenseCompiler::class);

    $reacted = $compiler->compileFileFilter([
        'reactionMode' => 'reacted',
        'reactionTypes' => null,
        'fileTypes' => ['all'],
        'downloaded' => 'any',
        'blacklisted' => 'any',
        'autoDisliked' => 'any',
    ], 9);

    $typed = $compiler->compileFileFilter([
        'reactionMode' => 'types',
        'reactionTypes' => ['dislike', 'funny'],
        'fileTypes' => ['all'],
        'downloaded' => 'any',
        'blacklisted' => 'any',
        'autoDisliked' => 'any',
    ], 9);

    $unreacted = $compiler->compileFileFilter([
        'reactionMode' => 'unreacted',
        'reactionTypes' => null,
        'fileTypes' => ['all'],
        'downloaded' => 'any',
        'blacklisted' => 'any',
        'autoDisliked' => 'any',
    ], 9);

    expect($reacted)->toContain('love_user_ids:=[9]')
        ->and($reacted)->toContain('not_found:=false')
        ->and($reacted)->toContain('like_user_ids:=[9]')
        ->and($reacted)->toContain('funny_user_ids:=[9]')
        ->and($typed)->toContain('not_found:=false')
        ->and($typed)->toContain('dislike_user_ids:=[9]')
        ->and($typed)->toContain('funny_user_ids:=[9]')
        ->and($unreacted)->toContain('not_found:=false')
        ->and($unreacted)->toContain('reacted_user_ids:!=[9]');
});

test('compiler builds reaction timestamp queries for descending and ascending sorts', function () {
    $compiler = app(LocalBrowseTypesenseCompiler::class);

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
        'autoDisliked' => 'no',
        'allTypes' => ['love', 'like', 'dislike', 'funny'],
    ], 5, 'atlas_local_local_browse_files__v20260331_000000');

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
        'reactionTypes' => ['dislike'],
        'autoDisliked' => 'no',
        'allTypes' => ['love', 'like', 'dislike', 'funny'],
    ], 5, 'atlas_local_local_browse_files__v20260331_000000');

    expect($desc['mode'])->toBe('reactions')
        ->and($desc['collection'])->toBe(app(LocalBrowseTypesenseNames::class)->reactionsAlias())
        ->and($desc['options']['filter_by'])->toContain('user_id:=5')
        ->and($desc['options']['filter_by'])->toContain('type:=[`love`, `like`, `funny`]')
        ->and($desc['options']['filter_by'])->toContain('$atlas_local_local_browse_files__v20260331_000000(')
        ->and($desc['options']['filter_by'])->toContain('not_found:=false')
        ->and($desc['options']['filter_by'])->toContain('source:=`CivitAI`')
        ->and($desc['options']['filter_by'])->toContain('downloaded:=true')
        ->and($desc['options']['filter_by'])->toContain('blacklisted:=false')
        ->and($desc['options']['filter_by'])->toContain('previewed_count:<=1')
        ->and($desc['options']['filter_by'])->toContain('mime_group:=[`image`, `video`]')
        ->and($desc['options']['filter_by'])->toContain('auto_disliked:=false')
        ->and($desc['options']['sort_by'])->toBe('created_at:desc,sort_id:desc')
        ->and($asc['options']['filter_by'])->toContain('type:=[`dislike`]')
        ->and($asc['options']['sort_by'])->toBe('created_at:asc,sort_id:asc');
});
