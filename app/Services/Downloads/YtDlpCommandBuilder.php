<?php

namespace App\Services\Downloads;

class YtDlpCommandBuilder
{
    /**
     * @param  array{cookies_path?: string, user_agent?: string}  $runtimeOptions
     * @return array<int, string>
     */
    public function build(string $url, string $outputTemplate, array $runtimeOptions = []): array
    {
        $ytDlp = (string) config('downloads.yt_dlp_path', 'yt-dlp');
        $ffmpeg = (string) config('downloads.ffmpeg_path', 'ffmpeg');
        $jsRuntimes = trim((string) config('downloads.yt_dlp_js_runtimes', 'node'));
        $runtimeCookiesPath = trim((string) ($runtimeOptions['cookies_path'] ?? ''));
        $runtimeUserAgent = trim((string) ($runtimeOptions['user_agent'] ?? ''));
        $cookiesPath = trim((string) config('downloads.yt_dlp_cookies_path', ''));
        $cookiesFromBrowser = trim((string) config('downloads.yt_dlp_cookies_from_browser', ''));
        $concurrentFragments = max(1, (int) config('downloads.yt_dlp_concurrent_fragments', 1));

        $args = [
            $ytDlp,
            '--no-playlist',
        ];

        // Ensure yt-dlp can merge audio/video streams (Windows queue workers often don't inherit PATH).
        if ($ffmpeg !== '') {
            $args[] = '--ffmpeg-location';
            $args[] = $ffmpeg;
        }

        if ($jsRuntimes !== '') {
            $args[] = '--js-runtimes';
            $args[] = $jsRuntimes;
        }

        if ($runtimeCookiesPath !== '') {
            $args[] = '--cookies';
            $args[] = $runtimeCookiesPath;
        } elseif ($cookiesPath !== '') {
            $args[] = '--cookies';
            $args[] = $cookiesPath;
        } elseif ($cookiesFromBrowser !== '') {
            $args[] = '--cookies-from-browser';
            $args[] = $cookiesFromBrowser;
        }

        if ($runtimeUserAgent !== '') {
            $args[] = '--user-agent';
            $args[] = $runtimeUserAgent;
        }

        if ($concurrentFragments > 1) {
            $args[] = '--concurrent-fragments';
            $args[] = (string) $concurrentFragments;
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
