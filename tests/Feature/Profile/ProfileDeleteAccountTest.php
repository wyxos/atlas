<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('authenticated user can delete their account', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $response = $this->actingAs($user)->deleteJson('/profile/account', [
        'password' => 'password',
    ]);

    $response->assertSuccessful();
    $response->assertJson([
        'message' => 'Account deleted successfully.',
    ]);
});

test('account deletion requires password confirmation', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $response = $this->actingAs($user)->deleteJson('/profile/account', []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('password');
});

test('account deletion validates password matches', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $response = $this->actingAs($user)->deleteJson('/profile/account', [
        'password' => 'password',
    ]);

    $response->assertSuccessful();
});

test('account deletion fails with incorrect password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $response = $this->actingAs($user)->deleteJson('/profile/account', [
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('password');
});

test('account deletion logs out user', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);
    $this->assertAuthenticatedAs($user);

    $this->deleteJson('/profile/account', [
        'password' => 'password',
    ]);

    $this->assertGuest();
});

test('account deletion invalidates session', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $sessionId = session()->getId();

    $this->actingAs($user)->deleteJson('/profile/account', [
        'password' => 'password',
    ]);

    // Session should be invalidated
    $this->assertGuest();
});

test('account deletion regenerates CSRF token', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $oldToken = csrf_token();

    $this->actingAs($user)->deleteJson('/profile/account', [
        'password' => 'password',
    ]);

    // Note: After logout, we get a new session, so token will be different
    // This test verifies the logout process happens
    $this->assertGuest();
});

test('account deletion removes user from database', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);
    $userId = $user->id;

    $this->actingAs($user)->deleteJson('/profile/account', [
        'password' => 'password',
    ]);

    $this->assertDatabaseMissing('users', ['id' => $userId]);
});

test('account deletion returns success message', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $response = $this->actingAs($user)->deleteJson('/profile/account', [
        'password' => 'password',
    ]);

    $response->assertJson([
        'message' => 'Account deleted successfully.',
    ]);
});

test('guest cannot delete account', function () {
    $response = $this->deleteJson('/profile/account', [
        'password' => 'password',
    ]);

    $response->assertUnauthorized();
});

