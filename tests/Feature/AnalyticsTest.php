<?php

it('renders the Google Analytics tag when analytics is enabled', function () {
    config([
        'services.google_analytics.enabled' => true,
        'services.google_analytics.measurement_id' => 'G-TEST000000',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('https://www.googletagmanager.com/gtag/js?id=G-TEST000000', false)
        ->assertSee('gtag(\'config\', "G-TEST000000");', false);
});

it('does not render Google Analytics when analytics is disabled', function () {
    config([
        'services.google_analytics.enabled' => false,
        'services.google_analytics.measurement_id' => 'G-TEST000000',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('googletagmanager.com', false)
        ->assertDontSee('G-TEST000000', false);
});

it('does not render Google Analytics when the measurement id is missing', function () {
    config([
        'services.google_analytics.enabled' => true,
        'services.google_analytics.measurement_id' => null,
    ]);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('googletagmanager.com', false);
});
