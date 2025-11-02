<?php

use App\Models\User;

test('users list shows created users for admin', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    // Create some non-admin users to appear in the list
    $users = User::factory()->count(3)->create();

    $this->actingAs($admin);

    $response = visit(route('users'));

    $response->assertNoSmoke();

    $response->assertSee('Users');

    foreach ($users as $u) {
        $response->assertSee($u->name);
        $response->assertSee($u->email);
    }
});

test('non-admin cannot access users list', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $this->actingAs($user);

    // Browser smoke check even on forbidden
    $response = visit(route('users'));
    $response->assertNoSmoke();

    // HTTP assertion for forbidden status
    $this->get(route('users'))
        ->assertStatus(403);
});

test('users list pagination shows next page', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    // Create a sentinel user first so it falls onto page 2 once we add many more
    $sentinel = User::factory()->create(['name' => 'Pagination Sentinel', 'email' => 'sentinel@example.test']);

    // Ensure more than the per-page (25) additional users so sentinel is on page 2
    User::factory()->count(30)->create();

    $this->actingAs($admin);

    $response = visit(route('users'));

    $response->assertNoSmoke();

    $response->assertSee('Users');
    $response->assertSee('Page 1 of');
    $response->assertDontSee('Pagination Sentinel');

    // Go to next page and verify the content updates
    $response->click('Next')
        ->assertNoSmoke()
        ->assertSee('Page 2 of')
        ->assertSee('Pagination Sentinel')
        ->assertSee('Users');
});
