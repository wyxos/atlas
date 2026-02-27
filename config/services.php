<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'civitai' => [
        'key' => env('CIVITAI_API'),
    ],

    'wallhaven' => [
        'key' => env('WALLHAVEN_API'),
        'user_agent' => env('WALLHAVEN_USER_AGENT', 'Atlas/1.0 (+https://wallhaven.cc)'),
    ],

    'spotify' => [
        'client_id' => env('SPOTIFY_CLIENT_ID'),
        'client_secret' => env('SPOTIFY_CLIENT_SECRET'),
        'redirect_uri' => env('SPOTIFY_REDIRECT_URI', rtrim((string) env('APP_URL', ''), '/').'/auth/spotify/callback'),
        'scopes' => env('SPOTIFY_SCOPES', 'user-read-email user-read-private playlist-read-private playlist-read-collaborative streaming user-read-playback-state user-modify-playback-state user-read-currently-playing'),
        'authorize_url' => env('SPOTIFY_AUTHORIZE_URL', 'https://accounts.spotify.com/authorize'),
        'token_url' => env('SPOTIFY_TOKEN_URL', 'https://accounts.spotify.com/api/token'),
        'api_base_url' => env('SPOTIFY_API_BASE_URL', 'https://api.spotify.com/v1'),
    ],

];
