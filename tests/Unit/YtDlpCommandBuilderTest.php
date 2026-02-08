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
