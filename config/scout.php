<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Search Engine
    |--------------------------------------------------------------------------
    |
    | This option controls the default search connection that gets used while
    | using Laravel Scout. This connection is used when syncing all models
    | to the search service. You should adjust this based on your needs.
    |
    | Supported: "algolia", "meilisearch", "typesense",
    |            "database", "collection", "null"
    |
    */

    'driver' => env('SCOUT_DRIVER', 'collection'),

    /*
    |--------------------------------------------------------------------------
    | Index Prefix
    |--------------------------------------------------------------------------
    |
    | Here you may specify a prefix that will be applied to all search index
    | names used by Scout. This prefix may be useful if you have multiple
    | "tenants" or applications sharing the same search infrastructure.
    |
    */

    'prefix' => env('SCOUT_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Queue Data Syncing
    |--------------------------------------------------------------------------
    |
    | This option allows you to control if the operations that sync your data
    | with your search engines are queued. When this is set to "true" then
    | all automatic data syncing will get queued for better performance.
    |
    */

    'queue' => env('SCOUT_QUEUE') ? [
        'queue' => env('SCOUT_QUEUE'),
    ] : null,

    /*
    |--------------------------------------------------------------------------
    | Database Transactions
    |--------------------------------------------------------------------------
    |
    | This configuration option determines if your data will only be synced
    | with your search indexes after every open database transaction has
    | been committed, thus preventing any discarded data from syncing.
    |
    */

    'after_commit' => false,

    /*
    |--------------------------------------------------------------------------
    | Chunk Sizes
    |--------------------------------------------------------------------------
    |
    | These options allow you to control the maximum chunk size when you are
    | mass importing data into the search engine. This allows you to fine
    | tune each of these chunk sizes based on the power of the servers.
    |
    */

    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft Deletes
    |--------------------------------------------------------------------------
    |
    | This option allows to control whether to keep soft deleted records in
    | the search indexes. Maintaining soft deleted records can be useful
    | if your application still needs to search for the records later.
    |
    */

    'soft_delete' => false,

    /*
    |--------------------------------------------------------------------------
    | Identify User
    |--------------------------------------------------------------------------
    |
    | This option allows you to control whether to notify the search engine
    | of the user performing the search. This is sometimes useful if the
    | engine supports any analytics based on this application's users.
    |
    | Supported engines: "algolia"
    |
    */

    'identify' => env('SCOUT_IDENTIFY', false),

    /*
    |--------------------------------------------------------------------------
    | Algolia Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your Algolia settings. Algolia is a cloud hosted
    | search engine which works great with Scout out of the box. Just plug
    | in your application ID and admin API key to get started searching.
    |
    */

    'algolia' => [
        'id' => env('ALGOLIA_APP_ID', ''),
        'secret' => env('ALGOLIA_SECRET', ''),
        'index-settings' => [
            // 'users' => [
            //     'searchableAttributes' => ['id', 'name', 'email'],
            //     'attributesForFaceting'=> ['filterOnly(email)'],
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Meilisearch Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your Meilisearch settings. Meilisearch is an open
    | source search engine with minimal configuration. Below, you can state
    | the host and key information for your own Meilisearch installation.
    |
    | See: https://www.meilisearch.com/docs/learn/configuration/instance_options#all-instance-options
    |
    */

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index-settings' => [
            // 'users' => [
            //     'filterableAttributes'=> ['id', 'name', 'email'],
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Typesense Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your Typesense settings. Typesense is an open
    | source search engine using minimal configuration. Below, you will
    | state the host, key, and schema configuration for the instance.
    |
    */

    'typesense' => [
        'client-settings' => [
            'api_key' => env('TYPESENSE_API_KEY', 'xyz'),
            'nodes' => [
                [
                    'host' => env('TYPESENSE_HOST', 'localhost'),
                    'port' => env('TYPESENSE_PORT', '8108'),
                    'path' => env('TYPESENSE_PATH', ''),
                    'protocol' => env('TYPESENSE_PROTOCOL', 'http'),
                ],
            ],
            'nearest_node' => [
                'host' => env('TYPESENSE_HOST', 'localhost'),
                'port' => env('TYPESENSE_PORT', '8108'),
                'path' => env('TYPESENSE_PATH', ''),
                'protocol' => env('TYPESENSE_PROTOCOL', 'http'),
            ],
            'connection_timeout_seconds' => env('TYPESENSE_CONNECTION_TIMEOUT_SECONDS', 2),
            'healthcheck_interval_seconds' => env('TYPESENSE_HEALTHCHECK_INTERVAL_SECONDS', 30),
            'num_retries' => env('TYPESENSE_NUM_RETRIES', 3),
            'retry_interval_seconds' => env('TYPESENSE_RETRY_INTERVAL_SECONDS', 1),
        ],
        // 'max_total_results' => env('TYPESENSE_MAX_TOTAL_RESULTS', 1000),
        'model-settings' => [
            App\Models\File::class => [
                'collection-schema' => [
                    'fields' => [
                        ['name' => 'id', 'type' => 'string'],
                        ['name' => 'source', 'type' => 'string', 'optional' => true, 'facet' => true],
                        ['name' => 'source_id', 'type' => 'string', 'optional' => true],
                        ['name' => 'url', 'type' => 'string', 'optional' => true],
                        ['name' => 'referrer_url', 'type' => 'string', 'optional' => true],
                        ['name' => 'path', 'type' => 'string', 'optional' => true],
                        ['name' => 'has_path', 'type' => 'bool', 'facet' => true],
                        ['name' => 'filename', 'type' => 'string'],
                        ['name' => 'ext', 'type' => 'string', 'optional' => true],
                        ['name' => 'size', 'type' => 'int64', 'optional' => true],
                        ['name' => 'mime_type', 'type' => 'string', 'optional' => true, 'facet' => true],
                        ['name' => 'mime_group', 'type' => 'string', 'facet' => true],
                        ['name' => 'hash', 'type' => 'string', 'optional' => true],
                        ['name' => 'title', 'type' => 'string', 'optional' => true],
                        ['name' => 'description', 'type' => 'string', 'optional' => true],
                        ['name' => 'thumbnail_url', 'type' => 'string', 'optional' => true],
                        ['name' => 'thumbnail_path', 'type' => 'string', 'optional' => true],
                        ['name' => 'tags', 'type' => 'string[]', 'optional' => true],
                        ['name' => 'parent_id', 'type' => 'int32', 'optional' => true],
                        ['name' => 'chapter', 'type' => 'string', 'optional' => true],
                        ['name' => 'previewed_at', 'type' => 'int64', 'optional' => true],
                        ['name' => 'seen_at', 'type' => 'int64', 'optional' => true],
                        ['name' => 'previewed_count', 'type' => 'int32', 'optional' => true],
                        ['name' => 'seen_count', 'type' => 'int32', 'optional' => true],
                        ['name' => 'blacklisted_at', 'type' => 'int64', 'optional' => true],
                        ['name' => 'blacklisted', 'type' => 'bool', 'optional' => true, 'facet' => true],
                        ['name' => 'blacklist_reason', 'type' => 'string', 'optional' => true],
                        ['name' => 'blacklist_type', 'type' => 'string', 'optional' => true, 'facet' => true],
                        ['name' => 'downloaded', 'type' => 'bool', 'optional' => true],
                        ['name' => 'download_progress', 'type' => 'int32', 'optional' => true],
                        ['name' => 'downloaded_at', 'type' => 'int64', 'optional' => true],
                        ['name' => 'not_found', 'type' => 'bool', 'optional' => true, 'facet' => true],
                        ['name' => 'auto_disliked', 'type' => 'bool', 'optional' => true, 'facet' => true],
                        ['name' => 'metadata_title', 'type' => 'string', 'optional' => true],
                        ['name' => 'metadata_genre', 'type' => 'string', 'optional' => true],
                        ['name' => 'metadata_year', 'type' => 'string', 'optional' => true],
                        ['name' => 'metadata_comment', 'type' => 'string', 'optional' => true],
                        ['name' => 'metadata_track', 'type' => 'string', 'optional' => true],
                        // Per-user reaction arrays for per-user filtering
                        ['name' => 'love_user_ids', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                        ['name' => 'like_user_ids', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                        ['name' => 'dislike_user_ids', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                        ['name' => 'funny_user_ids', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                        ['name' => 'reacted_user_ids', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                        ['name' => 'has_reactions', 'type' => 'bool', 'optional' => true, 'facet' => true],
                        ['name' => 'has_love', 'type' => 'bool', 'optional' => true, 'facet' => true],
                        ['name' => 'has_like', 'type' => 'bool', 'optional' => true, 'facet' => true],
                        ['name' => 'has_dislike', 'type' => 'bool', 'optional' => true, 'facet' => true],
                        ['name' => 'has_funny', 'type' => 'bool', 'optional' => true, 'facet' => true],
                        ['name' => 'created_at', 'type' => 'int64'],
                        ['name' => 'updated_at', 'type' => 'int64'],
                    ],
                    'default_sorting_field' => 'created_at',
                ],
                'search-parameters' => [
                    'query_by' => 'filename,title,description,tags,path,metadata_title,metadata_genre,metadata_comment',
                    'filter_by' => '',
                ],
            ],
        ],
        'import_action' => env('TYPESENSE_IMPORT_ACTION', 'upsert'),
    ],

];
