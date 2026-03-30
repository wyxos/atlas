<?php

return [
    'disk' => 'atlas-app',

    'max_transfers_total' => (int) env('DOWNLOADS_MAX_TRANSFERS_TOTAL', 20),

    'max_transfers_per_domain' => (int) env('DOWNLOADS_MAX_TRANSFERS_PER_DOMAIN', 5),

    'chunk_count' => (int) env('DOWNLOADS_CHUNK_COUNT', 4),

    'min_bytes_for_chunking' => (int) env('DOWNLOADS_MIN_BYTES_FOR_CHUNKING', 10 * 1024 * 1024),

    'http_timeout_seconds' => (int) env('DOWNLOADS_HTTP_TIMEOUT_SECONDS', 30),

    'bulk_removal_sync_limit' => (int) env('DOWNLOADS_BULK_REMOVAL_SYNC_LIMIT', 200),

    'bulk_removal_chunk_size' => (int) env('DOWNLOADS_BULK_REMOVAL_CHUNK_SIZE', 100),

    'tmp_dir' => env('DOWNLOADS_TMP_DIR', 'downloads/.tmp'),

    'ffmpeg_path' => env('DOWNLOADS_FFMPEG_PATH', 'ffmpeg'),

    'ffmpeg_timeout_seconds' => (int) env('DOWNLOADS_FFMPEG_TIMEOUT_SECONDS', 120),

    // Preview-generation jobs can need more headroom than the default CLI PHP limit.
    'preview_php_memory_limit' => env('DOWNLOADS_PREVIEW_PHP_MEMORY_LIMIT', '512M'),

    'video_preview_width' => (int) env('DOWNLOADS_VIDEO_PREVIEW_WIDTH', 450),

    // Duration of generated video previews (in seconds).
    // Keep this short: previews are for fast grid rendering, not full playback.
    'video_preview_seconds' => (float) env('DOWNLOADS_VIDEO_PREVIEW_SECONDS', 6),

    'video_poster_second' => (float) env('DOWNLOADS_VIDEO_POSTER_SECOND', 1),

    'yt_dlp_path' => env('DOWNLOADS_YT_DLP_PATH', 'yt-dlp'),

    'yt_dlp_timeout_seconds' => (int) env('DOWNLOADS_YT_DLP_TIMEOUT_SECONDS', 1800),

    // Explicit JS challenge runtimes for yt-dlp extractor flows (notably YouTube).
    // Set empty to let yt-dlp defaults apply.
    'yt_dlp_js_runtimes' => env('DOWNLOADS_YT_DLP_JS_RUNTIMES', 'node'),

    // Parallel fragment fetching for segmented yt-dlp downloads (HLS/DASH).
    // Keep at 1 to disable and raise carefully on domains that tolerate it.
    'yt_dlp_concurrent_fragments' => (int) env('DOWNLOADS_YT_DLP_CONCURRENT_FRAGMENTS', 1),

    // Optional external downloader for yt-dlp handoffs (for example: aria2c).
    'yt_dlp_downloader' => env('DOWNLOADS_YT_DLP_DOWNLOADER'),

    // Optional raw args passed to yt-dlp via --downloader-args.
    'yt_dlp_downloader_args' => env('DOWNLOADS_YT_DLP_DOWNLOADER_ARGS'),

    // Optional Netscape cookie file path for authenticated yt-dlp runs
    // (useful for age-restricted/auth-required media on X/Twitter/Facebook).
    'yt_dlp_cookies_path' => env('DOWNLOADS_YT_DLP_COOKIES_PATH'),

    // Optional browser cookie source for yt-dlp (e.g. "firefox", "chrome", "edge").
    // Used only when DOWNLOADS_YT_DLP_COOKIES_PATH is not set.
    'yt_dlp_cookies_from_browser' => env('DOWNLOADS_YT_DLP_COOKIES_FROM_BROWSER'),
];
