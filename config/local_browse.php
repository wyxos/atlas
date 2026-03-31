<?php

$prefix = env('SCOUT_PREFIX', env('APP_ENV') === 'local' ? 'atlas_local_' : '');

return [
    'typesense' => [
        'files_alias' => env('LOCAL_BROWSE_TYPESENSE_FILES_ALIAS', $prefix.'local_browse_files'),
        'reactions_alias' => env('LOCAL_BROWSE_TYPESENSE_REACTIONS_ALIAS', $prefix.'local_browse_reactions'),
        'chunk' => (int) env('LOCAL_BROWSE_TYPESENSE_CHUNK', 500),
    ],
];
