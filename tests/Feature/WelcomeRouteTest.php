<?php

use Inertia\Testing\AssertableInertia as Assert;

/**
 * Feature tests for the home route to ensure backend returns the correct Inertia component.
 */
test('home route renders the Welcome component', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Welcome'));
});
