<?php

$prefix = env('SCOUT_PREFIX', env('APP_ENV') === 'local' ? 'atlas_local_' : '');

return [
    'typesense' => [
        'files_alias' => env('LIBRARY_TYPESENSE_FILES_ALIAS', $prefix.'library_files'),
        'reactions_alias' => env('LIBRARY_TYPESENSE_REACTIONS_ALIAS', $prefix.'library_reactions'),
        'chunk' => (int) env('LIBRARY_TYPESENSE_CHUNK', 500),
        'sync_queue' => env('LIBRARY_SYNC_QUEUE', 'library-sync'),
        'file_sync_queue' => env('LIBRARY_FILE_SYNC_QUEUE', 'library-file-sync'),
        'reaction_sync_queue' => env('LIBRARY_REACTION_SYNC_QUEUE', 'library-reaction-sync'),
        'delete_queue' => env('LIBRARY_DELETE_QUEUE', 'library-delete'),
        'reindex_queue' => env('LIBRARY_REINDEX_QUEUE', 'library-reindex'),
        'reindex_timeout' => (int) env('LIBRARY_REINDEX_TIMEOUT_SECONDS', 21600),
    ],
];
