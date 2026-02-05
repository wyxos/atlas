<?php

return [
    'disk' => 'atlas-app',

    'max_transfers_total' => (int) env('DOWNLOADS_MAX_TRANSFERS_TOTAL', 20),

    'max_transfers_per_domain' => (int) env('DOWNLOADS_MAX_TRANSFERS_PER_DOMAIN', 20),

    'chunk_count' => (int) env('DOWNLOADS_CHUNK_COUNT', 4),

    'min_bytes_for_chunking' => (int) env('DOWNLOADS_MIN_BYTES_FOR_CHUNKING', 10 * 1024 * 1024),

    'http_timeout_seconds' => (int) env('DOWNLOADS_HTTP_TIMEOUT_SECONDS', 30),

    'tmp_dir' => env('DOWNLOADS_TMP_DIR', 'downloads/.tmp'),

    'ffmpeg_path' => env('DOWNLOADS_FFMPEG_PATH', 'ffmpeg'),

    'ffmpeg_timeout_seconds' => (int) env('DOWNLOADS_FFMPEG_TIMEOUT_SECONDS', 120),

    'video_preview_width' => (int) env('DOWNLOADS_VIDEO_PREVIEW_WIDTH', 450),

    'video_poster_second' => (float) env('DOWNLOADS_VIDEO_POSTER_SECOND', 1),

    'extension_token' => env('ATLAS_EXTENSION_TOKEN'),
];
