<?php

use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps the custom label editor open and persists the custom label', function () {
    $user = User::factory()->create();

    $tab = Tab::factory()->create([
        'user_id' => $user->id,
        'label' => 'Generated Label',
        'custom_label' => null,
        'params' => [
            'page' => 1,
        ],
        'position' => 0,
        'is_active' => true,
    ]);

    $this->actingAs($user);

    $tabSelector = sprintf('[data-test="browse-tab-%d"]', $tab->id);
    $renameActionSelector = '[data-test="tab-context-rename"]';
    $inputSelector = '[data-test="tab-custom-label-input"]';
    $customLabel = 'Pinned Search V2!?';

    $page = visit('/browse');

    $page
        ->assertScript(sprintf('document.querySelector(%s) !== null', json_encode($tabSelector)))
        ->assertSeeIn($tabSelector, 'Generated Label')
        ->rightClick($tabSelector)
        ->assertScript(sprintf('document.querySelector(%s) !== null', json_encode($renameActionSelector)))
        ->click($renameActionSelector)
        ->assertScript(sprintf('document.querySelector(%s) !== null', json_encode($inputSelector)))
        ->assertScript("document.activeElement?.dataset?.test === 'tab-custom-label-input'")
        ->wait(0.2)
        ->assertScript(sprintf('document.querySelector(%s) !== null', json_encode($inputSelector)))
        ->assertScript("document.activeElement?.dataset?.test === 'tab-custom-label-input'")
        ->typeSlowly($inputSelector, $customLabel, 25)
        ->keys($inputSelector, 'Enter')
        ->wait(0.5)
        ->assertSeeIn($tabSelector, $customLabel)
        ->assertSeeIn($tabSelector, 'Generated Label')
        ->assertNoJavaScriptErrors();

    expect($tab->fresh()->custom_label)->toBe($customLabel);
});
