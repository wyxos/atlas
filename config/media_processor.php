<?php

return [
    'enabled' => (bool) env('MEDIA_PROCESSOR', false),

    'url' => env('MEDIA_PROCESSOR_URL'),

    'secret' => env('MEDIA_PROCESSOR_SECRET'),

    'instance' => env('MEDIA_PROCESSOR_INSTANCE', env('APP_ENV', 'local')),

    'storage_profile' => env('MEDIA_PROCESSOR_STORAGE_PROFILE', 'atlas-local'),

    'timeout_seconds' => (int) env('MEDIA_PROCESSOR_TIMEOUT_SECONDS', 15),

    'websocket_required' => (bool) env('MEDIA_PROCESSOR_WEBSOCKET_REQUIRED', true),

    'stale_task_minutes' => (int) env('MEDIA_PROCESSOR_STALE_TASK_MINUTES', 5),
];
