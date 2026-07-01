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

    'deviantart' => [
        'client_id' => env('DEVIANTART_CLIENT_ID'),
        'client_secret' => env('DEVIANTART_CLIENT_SECRET'),
        'redirect_uri' => env('DEVIANTART_REDIRECT_URI', rtrim((string) env('APP_URL', ''), '/').'/auth/deviantart/callback'),
        'scopes' => env('DEVIANTART_SCOPES', 'basic browse user user.manage'),
        'default_query' => env('DEVIANTART_DEFAULT_QUERY', ''),
        'user_agent' => env('DEVIANTART_USER_AGENT', 'Atlas/1.0 (+https://www.deviantart.com)'),
        'authorize_url' => env('DEVIANTART_AUTHORIZE_URL', 'https://www.deviantart.com/oauth2/authorize'),
        'token_url' => env('DEVIANTART_TOKEN_URL', 'https://www.deviantart.com/oauth2/token'),
        'api_base_url' => env('DEVIANTART_API_BASE_URL', 'https://www.deviantart.com/api/v1/oauth2'),
        'connect_timeout' => env('DEVIANTART_CONNECT_TIMEOUT', 6),
        'timeout' => env('DEVIANTART_TIMEOUT', 12),
        'max_retries' => env('DEVIANTART_MAX_RETRIES', 1),
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

    'audio_metadata' => [
        'fingerprinting_enabled' => env('AUDIO_METADATA_FINGERPRINTING_ENABLED', true),
        'remote_fingerprinting_enabled' => env('AUDIO_METADATA_REMOTE_FINGERPRINTING_ENABLED', true),
        'remote_fingerprint_timeout_seconds' => env('AUDIO_METADATA_REMOTE_FINGERPRINT_TIMEOUT_SECONDS', 75),
        'remote_fingerprint_poll_milliseconds' => env('AUDIO_METADATA_REMOTE_FINGERPRINT_POLL_MILLISECONDS', 250),
        'local_fingerprinting_enabled' => env('AUDIO_METADATA_LOCAL_FINGERPRINTING_ENABLED', true),
        'fpcalc_path' => env('AUDIO_METADATA_FPCALC_PATH', 'fpcalc'),
        'fpcalc_timeout_seconds' => env('AUDIO_METADATA_FPCALC_TIMEOUT_SECONDS', 45),
        'acoustid_client_key' => env('ACOUSTID_CLIENT_KEY'),
        'acoustid_api_base_url' => env('ACOUSTID_API_BASE_URL', 'https://api.acoustid.org/v2'),
        'acoustid_min_score' => env('ACOUSTID_MIN_SCORE', 0.65),
        'musicbrainz_api_base_url' => env('MUSICBRAINZ_API_BASE_URL', 'https://musicbrainz.org'),
        'cover_art_archive_base_url' => env('COVER_ART_ARCHIVE_BASE_URL', 'https://coverartarchive.org'),
        'discogs_user_token' => env('DISCOGS_USER_TOKEN'),
        'discogs_api_base_url' => env('DISCOGS_API_BASE_URL', 'https://api.discogs.com'),
        'vgmdb_enabled' => env('AUDIO_METADATA_VGMDB_ENABLED', true),
        'vgmdb_api_base_url' => env('AUDIO_METADATA_VGMDB_API_BASE_URL', 'https://vgmdb.info'),
        'vgmdb_timeout_seconds' => env('AUDIO_METADATA_VGMDB_TIMEOUT_SECONDS', 8),
        'spotify_catalog_enabled' => env('AUDIO_METADATA_SPOTIFY_CATALOG_ENABLED', true),
        'apple_enabled' => env('AUDIO_METADATA_APPLE_ENABLED', true),
        'apple_api_base_url' => env('AUDIO_METADATA_APPLE_API_BASE_URL', 'https://itunes.apple.com'),
        'apple_country' => env('AUDIO_METADATA_APPLE_COUNTRY', 'US'),
        'deezer_enabled' => env('AUDIO_METADATA_DEEZER_ENABLED', true),
        'deezer_api_base_url' => env('AUDIO_METADATA_DEEZER_API_BASE_URL', 'https://api.deezer.com'),
        'deezer_access_token' => env('AUDIO_METADATA_DEEZER_ACCESS_TOKEN'),
        'http_timeout_seconds' => env('AUDIO_METADATA_HTTP_TIMEOUT_SECONDS', 15),
        'queue_timeout_seconds' => env('AUDIO_METADATA_QUEUE_TIMEOUT_SECONDS', 1800),
        'user_agent' => env('AUDIO_METADATA_USER_AGENT', 'Atlas/1.0 (+'.rtrim((string) env('APP_URL', 'http://localhost'), '/').')'),
        'ai_enabled' => env('AUDIO_METADATA_AI_ENABLED', true),
        'ai_driver' => env('AUDIO_METADATA_AI_DRIVER', 'gateway'),
        'ai_provider' => env('AUDIO_METADATA_AI_PROVIDER', 'audio_metadata'),
        'ai_base_url' => env('AUDIO_METADATA_AI_BASE_URL', 'https://ai.wyxos.com/v1'),
        'ai_token' => env('AUDIO_METADATA_AI_TOKEN', env('LITELLM_MASTER_KEY')),
        'ai_model' => env('AUDIO_METADATA_AI_MODEL', 'local-fast'),
        'ai_timeout_seconds' => env('AUDIO_METADATA_AI_TIMEOUT', 90),
    ],

    'google_analytics' => [
        'enabled' => env('GOOGLE_ANALYTICS_ENABLED', env('APP_ENV') === 'production'),
        'measurement_id' => env('GOOGLE_ANALYTICS_MEASUREMENT_ID'),
    ],

];
