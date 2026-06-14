<?php

test('extension options preview is available with mocked data locally', function () {
    $response = $this->get('/__dev/extension-options');

    $response
        ->assertOk()
        ->assertSee('Atlas Extension Options')
        ->assertSee('data-vue-root="extension-options-preview"', false);
});
