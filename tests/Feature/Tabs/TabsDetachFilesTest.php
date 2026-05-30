<?php

use App\Models\File;
use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function attachFilesToTab(Tab $tab, array $fileIds): void
{
    $tab->files()->sync(collect($fileIds)->mapWithKeys(
        static fn (int $fileId, int $index): array => [$fileId => ['position' => $index]]
    )->all());
}

test('user can detach selected files from their target tab only', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $files = File::factory()->count(3)->create();
    $targetTab = Tab::factory()->for($user)->create();
    $otherOwnedTab = Tab::factory()->for($user)->create();
    $otherUserTab = Tab::factory()->for($otherUser)->create();

    attachFilesToTab($targetTab, $files->pluck('id')->all());
    attachFilesToTab($otherOwnedTab, [$files[0]->id]);
    attachFilesToTab($otherUserTab, [$files[0]->id]);

    $response = $this->actingAs($user)->deleteJson("/api/tabs/{$targetTab->id}/files", [
        'file_ids' => [$files[0]->id, $files[1]->id],
    ]);

    $response->assertSuccessful();
    $response->assertJson([
        'detached_file_ids' => [$files[0]->id, $files[1]->id],
        'detached_count' => 2,
    ]);

    $this->assertDatabaseMissing('tab_file', ['tab_id' => $targetTab->id, 'file_id' => $files[0]->id]);
    $this->assertDatabaseMissing('tab_file', ['tab_id' => $targetTab->id, 'file_id' => $files[1]->id]);
    $this->assertDatabaseHas('tab_file', ['tab_id' => $targetTab->id, 'file_id' => $files[2]->id]);
    $this->assertDatabaseHas('tab_file', ['tab_id' => $otherOwnedTab->id, 'file_id' => $files[0]->id]);
    $this->assertDatabaseHas('tab_file', ['tab_id' => $otherUserTab->id, 'file_id' => $files[0]->id]);
});

test('user cannot detach files from another user tab', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $file = File::factory()->create();
    $tab = Tab::factory()->for($otherUser)->create();
    attachFilesToTab($tab, [$file->id]);

    $response = $this->actingAs($user)->deleteJson("/api/tabs/{$tab->id}/files", [
        'file_ids' => [$file->id],
    ]);

    $response->assertForbidden();
    $this->assertDatabaseHas('tab_file', ['tab_id' => $tab->id, 'file_id' => $file->id]);
});

test('guest cannot detach tab files', function () {
    $user = User::factory()->create();
    $file = File::factory()->create();
    $tab = Tab::factory()->for($user)->create();
    attachFilesToTab($tab, [$file->id]);

    $response = $this->deleteJson("/api/tabs/{$tab->id}/files", [
        'file_ids' => [$file->id],
    ]);

    $response->assertUnauthorized();
    $this->assertDatabaseHas('tab_file', ['tab_id' => $tab->id, 'file_id' => $file->id]);
});

test('detach files validates file ids payload', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create();

    $this->actingAs($user)
        ->deleteJson("/api/tabs/{$tab->id}/files", ['file_ids' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file_ids']);

    $this->actingAs($user)
        ->deleteJson("/api/tabs/{$tab->id}/files", ['file_ids' => [1, 1]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file_ids.1']);
});
