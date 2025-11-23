<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('allows authenticated users to access the profile page', function () {
    $user = User::factory()->create();

    // Profile is an SPA route, so Laravel serves the dashboard view
    // Vue Router handles the client-side routing to the Profile component
    $this->actingAs($user)
        ->get('/profile')
        ->assertSuccessful();
});

it('allows users to update their password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('oldpassword'),
    ]);

    $this->actingAs($user)
        ->postJson('/profile/password', [
            'current_password' => 'oldpassword',
            'password' => 'newpassword',
            'password_confirmation' => 'newpassword',
        ])
        ->assertSuccessful()
        ->assertJson([
            'message' => 'Password updated successfully.',
        ]);

    // Verify password was updated
    $user->refresh();
    expect(Hash::check('newpassword', $user->password))->toBeTrue();
    expect(Hash::check('oldpassword', $user->password))->toBeFalse();
});

it('requires current password to update password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('oldpassword'),
    ]);

    $this->actingAs($user)
        ->postJson('/profile/password', [
            'current_password' => 'wrongpassword',
            'password' => 'newpassword',
            'password_confirmation' => 'newpassword',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);
});

it('requires password confirmation to match', function () {
    $user = User::factory()->create([
        'password' => Hash::make('oldpassword'),
    ]);

    $this->actingAs($user)
        ->postJson('/profile/password', [
            'current_password' => 'oldpassword',
            'password' => 'newpassword',
            'password_confirmation' => 'differentpassword',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});

it('allows users to delete their account', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user)
        ->deleteJson('/profile/account', [
            'password' => 'password',
        ])
        ->assertSuccessful()
        ->assertJson([
            'message' => 'Account deleted successfully.',
        ]);

    // Verify user was deleted
    expect(User::find($user->id))->toBeNull();
});

it('requires password to delete account', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user)
        ->deleteJson('/profile/account', [
            'password' => 'wrongpassword',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});

it('redirects guest users from profile to login', function () {
    $this->get('/profile')
        ->assertRedirect('/login');
});
