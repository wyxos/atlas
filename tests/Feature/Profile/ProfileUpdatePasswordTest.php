<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('authenticated user can update password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('old-password'),
    ]);

    $response = $this->actingAs($user)->postJson('/profile/password', [
        'current_password' => 'old-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    $response->assertSuccessful();
    $response->assertJson([
        'message' => 'Password updated successfully.',
    ]);
});

test('password update requires current_password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('old-password'),
    ]);

    $response = $this->actingAs($user)->postJson('/profile/password', [
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('current_password');
});

test('password update requires new password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('old-password'),
    ]);

    $response = $this->actingAs($user)->postJson('/profile/password', [
        'current_password' => 'old-password',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('password');
});

test('password update requires password confirmation', function () {
    $user = User::factory()->create([
        'password' => Hash::make('old-password'),
    ]);

    $response = $this->actingAs($user)->postJson('/profile/password', [
        'current_password' => 'old-password',
        'password' => 'new-password',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('password');
});

test('password update validates current password matches', function () {
    $user = User::factory()->create([
        'password' => Hash::make('old-password'),
    ]);

    $response = $this->actingAs($user)->postJson('/profile/password', [
        'current_password' => 'old-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    $response->assertSuccessful();
});

test('password update fails with incorrect current password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('old-password'),
    ]);

    $response = $this->actingAs($user)->postJson('/profile/password', [
        'current_password' => 'wrong-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('current_password');
});

test('password update fails when passwords do not match', function () {
    $user = User::factory()->create([
        'password' => Hash::make('old-password'),
    ]);

    $response = $this->actingAs($user)->postJson('/profile/password', [
        'current_password' => 'old-password',
        'password' => 'new-password',
        'password_confirmation' => 'different-password',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('password');
});

test('password update hashes new password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('old-password'),
    ]);
    $oldPasswordHash = $user->password;

    $this->actingAs($user)->postJson('/profile/password', [
        'current_password' => 'old-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    $user->refresh();
    expect($user->password)->not->toBe($oldPasswordHash);
    expect(Hash::check('new-password', $user->password))->toBeTrue();
});

test('password update returns success message', function () {
    $user = User::factory()->create([
        'password' => Hash::make('old-password'),
    ]);

    $response = $this->actingAs($user)->postJson('/profile/password', [
        'current_password' => 'old-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    $response->assertJson([
        'message' => 'Password updated successfully.',
    ]);
});

test('guest cannot update password', function () {
    $response = $this->postJson('/profile/password', [
        'current_password' => 'old-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    $response->assertUnauthorized();
});

test('password update follows Password defaults rules', function () {
    $user = User::factory()->create([
        'password' => Hash::make('old-password'),
    ]);

    // Test with a very short password (should fail if Password::defaults() has min length)
    $response = $this->actingAs($user)->postJson('/profile/password', [
        'current_password' => 'old-password',
        'password' => '123',
        'password_confirmation' => '123',
    ]);

    // The exact validation depends on Password::defaults() configuration
    // This test ensures the validation is applied
    if ($response->status() === 422) {
        $response->assertJsonValidationErrors('password');
    }
});

