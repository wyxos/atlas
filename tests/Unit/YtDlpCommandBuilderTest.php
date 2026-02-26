<?php

use App\Services\Downloads\YtDlpCommandBuilder;

it('builds a yt-dlp command including ffmpeg location and an audio-capable format', function () {
    config()->set('downloads.yt_dlp_path', 'yt-dlp');
    config()->set('downloads.ffmpeg_path', 'D:\\ffmpeg\\bin\\ffmpeg.exe');

    $builder = new YtDlpCommandBuilder;

    $args = $builder->build('https://example.com/watch?v=123', 'C:\\tmp\\download.%(ext)s');

    expect($args[0])->toBe('yt-dlp');
    expect($args)->toContain('--ffmpeg-location');
    expect($args)->toContain('D:\\ffmpeg\\bin\\ffmpeg.exe');
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
