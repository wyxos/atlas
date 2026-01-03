<?php

use App\Models\ModerationRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can list all moderation rules', function () {
    ModerationRule::factory()->count(3)->create();

    $response = $this->getJson('/api/moderation-rules');

    $response->assertOk()
        ->assertJsonCount(3);
});

test('returns empty array when no moderation rules exist', function () {
    $response = $this->getJson('/api/moderation-rules');

    $response->assertOk()
        ->assertJsonCount(0);
});

test('can create a moderation rule with any operation', function () {
    $payload = [
        'name' => 'Block spam terms',
        'op' => 'any',
        'terms' => ['spam', 'advertisement', 'promo'],
        'active' => true,
        'nsfw' => false,
        'action_type' => 'dislike',
        'options' => [
            'case_sensitive' => false,
            'whole_word' => true,
        ],
    ];

    $response = $this->postJson('/api/moderation-rules', $payload);

    $response->assertCreated()
        ->assertJson([
            'name' => 'Block spam terms',
            'op' => 'any',
            'terms' => ['spam', 'advertisement', 'promo'],
            'active' => true,
            'nsfw' => false,
            'action_type' => 'dislike',
        ]);

    $this->assertDatabaseHas('moderation_rules', [
        'name' => 'Block spam terms',
        'op' => 'any',
        'action_type' => 'dislike',
    ]);
});

test('can create a moderation rule with at_least operation', function () {
    $payload = [
        'name' => 'Quality check',
        'op' => 'at_least',
        'min' => 2,
        'terms' => ['hd', 'professional', 'high-quality'],
        'active' => true,
    ];

    $response = $this->postJson('/api/moderation-rules', $payload);

    $response->assertCreated()
        ->assertJson([
            'op' => 'at_least',
            'min' => 2,
            'terms' => ['hd', 'professional', 'high-quality'],
        ]);
});

test('can create a moderation rule without a name', function () {
    $payload = [
        'op' => 'any',
        'terms' => ['test'],
    ];

    $response = $this->postJson('/api/moderation-rules', $payload);

    $response->assertCreated()
        ->assertJson([
            'name' => null,
            'op' => 'any',
        ]);
});

test('requires op field when creating moderation rule', function () {
    $payload = [
        'name' => 'Test rule',
        'terms' => ['test'],
    ];

    $response = $this->postJson('/api/moderation-rules', $payload);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['op']);
});

test('validates op field must be valid operation', function () {
    $payload = [
        'op' => 'invalid_op',
        'terms' => ['test'],
    ];

    $response = $this->postJson('/api/moderation-rules', $payload);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['op']);
});

test('can show a specific moderation rule', function () {
    $rule = ModerationRule::factory()->any(['car', 'vehicle'])->create([
        'name' => 'Vehicle filter',
    ]);

    $response = $this->getJson("/api/moderation-rules/{$rule->id}");

    $response->assertOk()
        ->assertJson([
            'id' => $rule->id,
            'name' => 'Vehicle filter',
            'op' => 'any',
            'terms' => ['car', 'vehicle'],
        ]);
});

test('can update a moderation rule', function () {
    $rule = ModerationRule::factory()->create([
        'name' => 'Original name',
        'active' => true,
    ]);

    $response = $this->putJson("/api/moderation-rules/{$rule->id}", [
        'name' => 'Updated name',
        'active' => false,
    ]);

    $response->assertOk()
        ->assertJson([
            'name' => 'Updated name',
            'active' => false,
        ]);

    $this->assertDatabaseHas('moderation_rules', [
        'id' => $rule->id,
        'name' => 'Updated name',
        'active' => false,
    ]);
});

test('can update moderation rule terms', function () {
    $rule = ModerationRule::factory()->any(['old1', 'old2'])->create();

    $response = $this->putJson("/api/moderation-rules/{$rule->id}", [
        'terms' => ['new1', 'new2', 'new3'],
    ]);

    $response->assertOk()
        ->assertJson([
            'terms' => ['new1', 'new2', 'new3'],
        ]);
});

test('can delete a moderation rule', function () {
    $rule = ModerationRule::factory()->create();

    $response = $this->deleteJson("/api/moderation-rules/{$rule->id}");

    $response->assertOk()
        ->assertJson(['message' => 'Moderation rule deleted successfully']);

    $this->assertDatabaseMissing('moderation_rules', [
        'id' => $rule->id,
    ]);
});

test('returns 404 when moderation rule not found', function () {
    $response = $this->getJson('/api/moderation-rules/99999');

    $response->assertNotFound();
});

test('unauthenticated user cannot access moderation rules', function () {
    auth()->logout();

    $response = $this->getJson('/api/moderation-rules');

    $response->assertUnauthorized();
});

test('can create nsfw-only moderation rule', function () {
    $payload = [
        'name' => 'NSFW filter',
        'op' => 'any',
        'terms' => ['explicit', 'adult'],
        'nsfw' => true,
    ];

    $response = $this->postJson('/api/moderation-rules', $payload);

    $response->assertCreated()
        ->assertJson([
            'nsfw' => true,
        ]);
});

test('rules are ordered by name', function () {
    ModerationRule::factory()->create(['name' => 'Zebra rule']);
    ModerationRule::factory()->create(['name' => 'Alpha rule']);
    ModerationRule::factory()->create(['name' => 'Middle rule']);

    $response = $this->getJson('/api/moderation-rules');

    $response->assertOk();
    $data = $response->json();

    expect($data[0]['name'])->toBe('Alpha rule')
        ->and($data[1]['name'])->toBe('Middle rule')
        ->and($data[2]['name'])->toBe('Zebra rule');
});
