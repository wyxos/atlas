<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    // Run migrations to ensure the users table exists
    $this->artisan('migrate');
});

test('command creates a super admin with provided email and password', function () {
    $email = 'admin@example.com';
    $password = 'secure_password123';

    // Run the command with email and password
    $this->artisan("app:add-super-admin {$email} --password={$password}")
        ->expectsOutput('Super admin created successfully!')
        ->expectsOutput("Email: {$email}")
        ->expectsOutput('Password: [HIDDEN]')
        ->assertSuccessful();

    // Verify the user was created in the database
    $user = User::where('email', $email)->first();

    expect($user)->not->toBeNull();
    expect($user->name)->toBe('admin');
    expect($user->is_super_admin)->toBeTrue();
    expect(Hash::check($password, $user->password))->toBeTrue();
});

test('command creates a super admin with generated password when password is not provided', function () {
    $email = 'admin2@example.com';

    // Run the command with email only
    $this->artisan("app:add-super-admin {$email}")
        ->expectsOutput('Super admin created successfully!')
        ->expectsOutput("Email: {$email}")
        ->expectsOutputToContain('Generated password:')
        ->expectsOutput('Please save this password as it will not be shown again.')
        ->assertSuccessful();

    // Verify the user was created in the database
    $user = User::where('email', $email)->first();

    expect($user)->not->toBeNull();
    expect($user->name)->toBe('admin2');
    expect($user->is_super_admin)->toBeTrue();
});

test('command fails with invalid email format', function () {
    $invalidEmail = 'invalid-email';

    // Run the command with invalid email
    $this->artisan("app:add-super-admin {$invalidEmail}")
        ->expectsOutput('Invalid email format.')
        ->assertFailed();

    // Verify no user was created
    $user = User::where('email', $invalidEmail)->first();
    expect($user)->toBeNull();
});

test('command updates existing user to super admin', function () {
    $email = 'existing@example.com';

    // Create a user with the email (not a super admin)
    User::factory()->create([
        'email' => $email,
        'is_super_admin' => false,
    ]);

    // Run the command with existing email
    $this->artisan("app:add-super-admin {$email}")
        ->expectsOutput("User {$email} has been updated to super admin successfully!")
        ->assertSuccessful();

    // Verify the user was updated to super admin
    $user = User::where('email', $email)->first();
    expect($user->is_super_admin)->toBeTrue();

    // Verify there's still only one user with this email
    $count = User::where('email', $email)->count();
    expect($count)->toBe(1);
});
