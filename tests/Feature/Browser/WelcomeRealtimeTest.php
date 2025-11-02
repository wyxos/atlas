<?php

it('receives DemoPing over Reverb on the welcome page', function () {
    // Ensure broadcasting uses Reverb for this test run
    config()->set('broadcasting.default', 'reverb');

    // Load welcome page (no pre-broadcast). The page subscribes to channel 'demo'.
    $page = visit(route('home'))
        ->assertNoSmoke()
        ->assertSee('Atlas');

    // Give the page a moment to establish the WebSocket subscription
    usleep(200000); // 200ms

    // Trigger a broadcast while the page is open (test-only endpoint)
    $this->post(route('testing.reverb-demo'))->assertOk();

    // The page exposes a hidden marker with the last message when the event is received
    $page->assertSee('Realtime test ping');
});
