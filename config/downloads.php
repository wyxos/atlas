<?php

return [
    'disk' => 'atlas-app',

    'max_transfers_total' => (int) env('DOWNLOADS_MAX_TRANSFERS_TOTAL', 20),

    'max_transfers_per_domain' => (int) env('DOWNLOADS_MAX_TRANSFERS_PER_DOMAIN', 20),

    'chunk_count' => (int) env('DOWNLOADS_CHUNK_COUNT', 4),

    'min_bytes_for_chunking' => (int) env('DOWNLOADS_MIN_BYTES_FOR_CHUNKING', 10 * 1024 * 1024),

    'http_timeout_seconds' => (int) env('DOWNLOADS_HTTP_TIMEOUT_SECONDS', 30),

    'tmp_dir' => env('DOWNLOADS_TMP_DIR', 'downloads/.tmp'),
];
