<?php

use App\Models\File;
use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user can update their own browse tab', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create(['label' => 'Old Label']);

    $response = $this->actingAs($user)->putJson("/api/tabs/{$tab->id}", [
        'label' => 'New Label',
    ]);

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['label'])->toBe('New Label');
    $this->assertDatabaseHas('tabs', [
        'id' => $tab->id,
        'label' => 'New Label',
    ]);
});

test('user cannot update another user tab', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $tab = Tab::factory()->for($user2)->create(['label' => 'Original Label']);

    $response = $this->actingAs($user1)->putJson("/api/tabs/{$tab->id}", [
        'label' => 'Hacked Label',
    ]);

    $response->assertForbidden();
    $this->assertDatabaseHas('tabs', [
        'id' => $tab->id,
        'label' => 'Original Label',
    ]);
});

test('tab update accepts partial data sometimes rules', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create([
        'label' => 'Original Label',
        'query_params' => ['page' => 1],
    ]);

    $response = $this->actingAs($user)->putJson("/api/tabs/{$tab->id}", [
        'label' => 'Updated Label',
    ]);

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['label'])->toBe('Updated Label');
    // query_params should remain unchanged
    expect($data['query_params'])->toBe(['page' => 1]);
});

test('tab update validates label when provided', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create();

    $response = $this->actingAs($user)->putJson("/api/tabs/{$tab->id}", [
        'label' => str_repeat('a', 256),
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('label');
});

test('tab update validates position when provided', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create();

    $response = $this->actingAs($user)->putJson("/api/tabs/{$tab->id}", [
        'position' => -1,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('position');
});

test('tab update returns updated tab', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create();

    $response = $this->actingAs($user)->putJson("/api/tabs/{$tab->id}", [
        'label' => 'Updated Label',
        'query_params' => ['page' => 2],
    ]);

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['id'])->toBe($tab->id);
    expect($data['label'])->toBe('Updated Label');
    expect($data['query_params'])->toBe(['page' => 2]);
});

test('guest cannot update browse tabs', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create();

    $response = $this->putJson("/api/tabs/{$tab->id}", [
        'label' => 'Updated Label',
    ]);

    $response->assertUnauthorized();
});

test('updating non-existent tab returns 404', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->putJson('/api/tabs/99999', [
        'label' => 'Updated Label',
    ]);

    $response->assertNotFound();
});

test('tab update can sync files', function () {
    $user = User::factory()->create();
    $file1 = File::factory()->create();
    $file2 = File::factory()->create();
    $file3 = File::factory()->create();
    $tab = Tab::factory()->for($user)->withFiles([$file1->id, $file2->id])->create();

    $response = $this->actingAs($user)->putJson("/api/tabs/{$tab->id}", [
        'file_ids' => [$file3->id, $file1->id],
    ]);

    $response->assertSuccessful();
    $tab->refresh();
    expect($tab->files)->toHaveCount(2);
    expect($tab->files->pluck('id')->toArray())->toBe([$file3->id, $file1->id]);
});

test('tab update can remove all files', function () {
    $user = User::factory()->create();
    $file1 = File::factory()->create();
    $file2 = File::factory()->create();
    $tab = Tab::factory()->for($user)->withFiles([$file1->id, $file2->id])->create();

    $response = $this->actingAs($user)->putJson("/api/tabs/{$tab->id}", [
        'file_ids' => [],
    ]);

    $response->assertSuccessful();
    $tab->refresh();
    expect($tab->files)->toHaveCount(0);
});

test('tab update maintains file order', function () {
    $user = User::factory()->create();
    $file1 = File::factory()->create();
    $file2 = File::factory()->create();
    $file3 = File::factory()->create();
    $tab = Tab::factory()->for($user)->withFiles([$file1->id, $file2->id])->create();

    $response = $this->actingAs($user)->putJson("/api/tabs/{$tab->id}", [
        'file_ids' => [$file3->id, $file2->id, $file1->id],
    ]);

    $response->assertSuccessful();
    $tab->refresh();
    $files = $tab->files()->orderByPivot('position')->get();
    expect($files[0]->id)->toBe($file3->id);
    expect($files[1]->id)->toBe($file2->id);
    expect($files[2]->id)->toBe($file1->id);
});

test('tab update can change source type to local', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create(['query_params' => ['sourceType' => 'online']]);

    $response = $this->actingAs($user)->putJson("/api/tabs/{$tab->id}", [
        'query_params' => ['sourceType' => 'local'],
    ]);

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['query_params']['sourceType'] ?? null)->toBe('local');
    $tab->refresh();
    expect($tab->query_params['sourceType'] ?? null)->toBe('local');
});

test('tab update can change source type to online', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create(['query_params' => ['sourceType' => 'local']]);

    $response = $this->actingAs($user)->putJson("/api/tabs/{$tab->id}", [
        'query_params' => ['sourceType' => 'online'],
    ]);

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['query_params']['sourceType'] ?? null)->toBe('online');
    $tab->refresh();
    expect($tab->query_params['sourceType'] ?? null)->toBe('online');
});

test('validation fails when sourceType is invalid', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create();

    $response = $this->actingAs($user)->putJson("/api/tabs/{$tab->id}", [
        'query_params' => ['sourceType' => 'invalid'],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('query_params.sourceType');
});
