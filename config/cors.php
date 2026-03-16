<?php

return [
    'paths' => [
        'api/extension/*',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [
        '#^chrome-extension://[a-p]{32}$#',
        '#^moz-extension://[0-9a-f-]+$#i',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
