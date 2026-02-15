<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('csrf refresh endpoint returns no content and sets xsrf cookie', function () {
    $response = $this->get('/api/csrf');

    $response->assertNoContent();
    $response->assertCookie('XSRF-TOKEN');
});
