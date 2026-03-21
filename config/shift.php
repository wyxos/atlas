<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SHIFT Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration settings for the SHIFT SDK integration.
    |
    */

    'token' => env('SHIFT_TOKEN'),
    'project' => env('SHIFT_PROJECT'),
    'url' => env('SHIFT_URL', 'https://shift.wyxos.com'),
    'ai' => [
        'enabled' => env('SHIFT_AI_ENABLED', false),
    ],

    'collaborators' => [
        'resolver' => env('SHIFT_COLLABORATORS_RESOLVER', App\Services\ShiftCollaboratorResolver::class),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Routes
    |--------------------------------------------------------------------------
    |
    | Configure the default routes for the SHIFT SDK integration.
    |
    */

    'routes' => [
        'prefix' => 'shift',
        'middleware' => ['web', 'auth'],
    ],
];
