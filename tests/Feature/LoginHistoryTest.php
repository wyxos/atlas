<?php

use App\Models\LoginHistory;
use App\Models\User;
use App\Notifications\NewUserRegistered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('login history is recorded when a user logs in', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertDatabaseHas('login_histories', [
        'user_id' => $user->id,
    ]);

    $loginHistory = LoginHistory::where('user_id', $user->id)->first();
    expect($loginHistory)->not->toBeNull();
    expect($loginHistory->ip_address)->not->toBeNull();
    expect($loginHistory->user_agent)->not->toBeNull();
});

test('last login time is displayed on users list', function () {
    $user = User::factory()->create(['is_admin' => true]);

    // Create a login history for the user
    $loginHistory = LoginHistory::create([
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Test Browser',
    ]);

    $response = $this->actingAs($user)->get('/users');
    $response->assertStatus(200);

    // Assert that the response contains the user with last_login_at
    $response->assertInertia(fn ($page) => $page
        ->component('Users/Index')
        ->has('users.data')
    );

    // Get the response data and check if last_login_at is present
    $responseData = $response->viewData('page')['props'];
    $users = $responseData['users']['data'];

    // Find the user in the response
    $foundUser = null;
    foreach ($users as $responseUser) {
        if ($responseUser['id'] === $user->id) {
            $foundUser = $responseUser;
            break;
        }
    }

    expect($foundUser)->not->toBeNull();
    expect($foundUser)->toHaveKey('last_login_at');
});

test('admin receives notification when a new user registers', function () {
    Notification::fake();

    $admin = User::factory()->create(['is_admin' => true]);

    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    Notification::assertSentTo(
        $admin,
        NewUserRegistered::class
    );
});
