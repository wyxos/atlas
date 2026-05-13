<?php

$prefix = env('SCOUT_PREFIX', env('APP_ENV') === 'local' ? 'atlas_local_' : '');

return [
    'typesense' => [
        'files_alias' => env('LIBRARY_TYPESENSE_FILES_ALIAS', $prefix.'library_files'),
        'reactions_alias' => env('LIBRARY_TYPESENSE_REACTIONS_ALIAS', $prefix.'library_reactions'),
        'chunk' => (int) env('LIBRARY_TYPESENSE_CHUNK', 500),
        'sync_queue' => env('LIBRARY_SYNC_QUEUE', 'library-sync'),
        'reindex_queue' => env('LIBRARY_REINDEX_QUEUE', 'library-reindex'),
        'reindex_timeout' => (int) env('LIBRARY_REINDEX_TIMEOUT_SECONDS', 21600),
    ],
];
