<?php

use App\Models\File;
use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows a toast when reacting with funny in the grid', function () {
    $user = User::factory()->create();

    $dataUrlA = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO2U0ZcAAAAASUVORK5CYII=#file-a';
    $dataUrlB = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO2U0ZcAAAAASUVORK5CYII=#file-b';

    $fileA = File::factory()->create([
        'preview_url' => $dataUrlA,
        'url' => $dataUrlA,
        'mime_type' => 'image/png',
        'ext' => 'png',
        'downloaded' => false,
        'path' => null,
        'preview_path' => null,
    ]);

    $fileB = File::factory()->create([
        'preview_url' => $dataUrlB,
        'url' => $dataUrlB,
        'mime_type' => 'image/png',
        'ext' => 'png',
        'downloaded' => false,
        'path' => null,
        'preview_path' => null,
    ]);

    $tab = Tab::factory()->create([
        'user_id' => $user->id,
        'params' => [
            'service' => 'civit-ai-images',
            'page' => 1,
        ],
        'position' => 0,
        'is_active' => true,
    ]);

    $tab->files()->sync([
        $fileA->id => ['position' => 0],
        $fileB->id => ['position' => 1],
    ]);

    $this->actingAs($user);

    $fileASelector = sprintf('[data-file-id="%d"]', $fileA->id);
    $fileBSelector = sprintf('[data-file-id="%d"]', $fileB->id);
    $likeButtonSelector = sprintf('%s button[aria-label="Like"]', $fileASelector);
    $funnyButtonSelector = sprintf('%s button[aria-label="Funny"]', $fileBSelector);

    $page = visit('/browse');

    $page
        ->assertScript(sprintf('document.querySelector(%s) !== null', json_encode($fileASelector)))
        ->hover($fileASelector)
        ->assertScript(sprintf('document.querySelector(%s) !== null', json_encode($likeButtonSelector)))
        ->click($likeButtonSelector)
        ->assertSee("Liked file #{$fileA->id}");

    $page
        ->hover($fileBSelector)
        ->assertScript(sprintf('document.querySelector(%s) !== null', json_encode($funnyButtonSelector)))
        ->click($funnyButtonSelector)
        ->assertSee("Funny file #{$fileB->id}")
        ->assertNoJavaScriptErrors();
});
