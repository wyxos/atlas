<?php

return [
    'enabled' => (bool) env('ATLAS_MEDIA_PROCESSOR_ENABLED', false),

    'url' => env('ATLAS_MEDIA_PROCESSOR_URL'),

    'secret' => env('ATLAS_MEDIA_PROCESSOR_SECRET'),

    'instance' => env('ATLAS_MEDIA_PROCESSOR_INSTANCE', env('APP_ENV', 'local')),

    'storage_profile' => env('ATLAS_MEDIA_PROCESSOR_STORAGE_PROFILE', 'atlas-local'),

    'timeout_seconds' => (int) env('ATLAS_MEDIA_PROCESSOR_TIMEOUT_SECONDS', 15),

    'websocket_required' => (bool) env('ATLAS_MEDIA_PROCESSOR_WEBSOCKET_REQUIRED', true),

    'stale_task_minutes' => (int) env('ATLAS_MEDIA_PROCESSOR_STALE_TASK_MINUTES', 5),
];
