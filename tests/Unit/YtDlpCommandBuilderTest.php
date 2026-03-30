<?php

use App\Services\Downloads\YtDlpCommandBuilder;
use Tests\TestCase;

uses(TestCase::class);

it('builds a yt-dlp command including ffmpeg location and an audio-capable format', function () {
    config()->set('downloads.yt_dlp_path', 'yt-dlp');
    config()->set('downloads.ffmpeg_path', 'D:\\ffmpeg\\bin\\ffmpeg.exe');
    config()->set('downloads.yt_dlp_js_runtimes', 'node');

    $builder = new YtDlpCommandBuilder;

    $args = $builder->build('https://example.com/watch?v=123', 'C:\\tmp\\download.%(ext)s');

    expect($args[0])->toBe('yt-dlp');
    expect($args)->toContain('--ffmpeg-location');
    expect($args)->toContain('D:\\ffmpeg\\bin\\ffmpeg.exe');
    expect($args)->toContain('--js-runtimes');
    expect($args)->toContain('node');
    expect($args)->toContain('--format');
    expect($args)->toContain('bestvideo[ext=mp4]+bestaudio[ext=m4a]/bestvideo*+bestaudio/best');
});

it('adds cookies file argument when configured', function () {
    config()->set('downloads.yt_dlp_path', 'yt-dlp');
    config()->set('downloads.ffmpeg_path', 'ffmpeg');
    config()->set('downloads.yt_dlp_cookies_path', '/etc/atlas/yt-dlp-cookies.txt');
    config()->set('downloads.yt_dlp_cookies_from_browser', 'firefox');

    $builder = new YtDlpCommandBuilder;

    $args = $builder->build('https://x.com/devops_nk/status/1', '/tmp/download.%(ext)s');

    expect($args)->toContain('--cookies');
    expect($args)->toContain('/etc/atlas/yt-dlp-cookies.txt');
    expect($args)->not->toContain('--cookies-from-browser');
});

it('adds cookies-from-browser when cookies path is not configured', function () {
    config()->set('downloads.yt_dlp_path', 'yt-dlp');
    config()->set('downloads.ffmpeg_path', 'ffmpeg');
    config()->set('downloads.yt_dlp_cookies_path', null);
    config()->set('downloads.yt_dlp_cookies_from_browser', 'firefox');

    $builder = new YtDlpCommandBuilder;

    $args = $builder->build('https://x.com/devops_nk/status/1', '/tmp/download.%(ext)s');

    expect($args)->toContain('--cookies-from-browser');
    expect($args)->toContain('firefox');
    expect($args)->not->toContain('--cookies');
});

it('prefers runtime cookies and user agent over global cookie config', function () {
    config()->set('downloads.yt_dlp_path', 'yt-dlp');
    config()->set('downloads.ffmpeg_path', 'ffmpeg');
    config()->set('downloads.yt_dlp_cookies_path', '/etc/atlas/global-cookies.txt');
    config()->set('downloads.yt_dlp_cookies_from_browser', 'firefox');

    $builder = new YtDlpCommandBuilder;

    $args = $builder->build(
        'https://x.com/devops_nk/status/1',
        '/tmp/download.%(ext)s',
        [
            'cookies_path' => '/tmp/runtime-cookies.txt',
            'user_agent' => 'AtlasTestAgent/1.0',
        ],
    );

    expect($args)->toContain('--cookies');
    expect($args)->toContain('/tmp/runtime-cookies.txt');
    expect($args)->not->toContain('/etc/atlas/global-cookies.txt');
    expect($args)->not->toContain('--cookies-from-browser');
    expect($args)->toContain('--user-agent');
    expect($args)->toContain('AtlasTestAgent/1.0');
});

it('does not add js-runtimes when disabled', function () {
    config()->set('downloads.yt_dlp_path', 'yt-dlp');
    config()->set('downloads.ffmpeg_path', 'ffmpeg');
    config()->set('downloads.yt_dlp_js_runtimes', '   ');

    $builder = new YtDlpCommandBuilder;

    $args = $builder->build('https://example.com/watch?v=123', '/tmp/download.%(ext)s');

    expect($args)->not->toContain('--js-runtimes');
});

it('adds concurrent fragment fetching when configured above one', function () {
    config()->set('downloads.yt_dlp_path', 'yt-dlp');
    config()->set('downloads.ffmpeg_path', 'ffmpeg');
    config()->set('downloads.yt_dlp_concurrent_fragments', 4);

    $builder = new YtDlpCommandBuilder;

    $args = $builder->build('https://example.com/watch?v=123', '/tmp/download.%(ext)s');

    expect($args)->toContain('--concurrent-fragments');
    expect($args)->toContain('4');
});

it('adds external downloader arguments when configured', function () {
    config()->set('downloads.yt_dlp_path', 'yt-dlp');
    config()->set('downloads.ffmpeg_path', 'ffmpeg');
    config()->set('downloads.yt_dlp_downloader', 'aria2c');
    config()->set('downloads.yt_dlp_downloader_args', 'aria2c:-x8 -s8 -k1M');

    $builder = new YtDlpCommandBuilder;

    $args = $builder->build('https://example.com/watch?v=123', '/tmp/download.%(ext)s');

    expect($args)->toContain('--downloader');
    expect($args)->toContain('aria2c');
    expect($args)->toContain('--downloader-args');
    expect($args)->toContain('aria2c:-x8 -s8 -k1M');
});
