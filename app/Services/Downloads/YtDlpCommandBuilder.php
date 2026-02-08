<?php

namespace App\Services\Downloads;

class YtDlpCommandBuilder
{
    /**
     * @return array<int, string>
     */
    public function build(string $url, string $outputTemplate): array
    {
        $ytDlp = (string) config('downloads.yt_dlp_path', 'yt-dlp');
        $ffmpeg = (string) config('downloads.ffmpeg_path', 'ffmpeg');

        $args = [
            $ytDlp,
            '--no-playlist',
        ];

        // Ensure yt-dlp can merge audio/video streams (Windows queue workers often don't inherit PATH).
        if ($ffmpeg !== '') {
            $args[] = '--ffmpeg-location';
            $args[] = $ffmpeg;
        }

        // Prefer mp4+m4a when available (usually browser-friendly), otherwise fall back to "best".
        $args[] = '--format';
        $args[] = 'bestvideo[ext=mp4]+bestaudio[ext=m4a]/bestvideo*+bestaudio/best';

        $args[] = '--merge-output-format';
        $args[] = 'mp4';

        $args[] = '--output';
        $args[] = $outputTemplate;

        $args[] = $url;

        return $args;
    }
}
