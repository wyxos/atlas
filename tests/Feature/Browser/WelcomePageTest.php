<?php

test('visit welcome page', function () {
    $response = visit(route('home'));

    $response->assertNoSmoke();
    $response->assertSee('Atlas');
    $response->assertSee('Get Started');
    $response->assertSee('Sign In');

    $response->click('Sign In')
        ->assertUrlIs(route('login'))
        ->assertNoSmoke()
        ->assertSee('Log in');
});
