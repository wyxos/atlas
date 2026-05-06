<?php

namespace App\Services\LibraryScans;

use Symfony\Component\Process\Process;

class MediaProbeService
{
    /**
     * @return array<string, mixed>
     */
    public function probe(string $absolutePath): array
    {
        $ffprobe = $this->resolveFfprobePath();
        if ($ffprobe === null) {
            return [];
        }

        $process = new Process([
            $ffprobe,
            '-v',
            'error',
            '-show_format',
            '-show_streams',
            '-of',
            'json',
            $absolutePath,
        ]);
        $process->setTimeout((int) config('downloads.ffmpeg_timeout_seconds', 120));

        try {
            $process->run();
        } catch (\Throwable) {
            return [];
        }

        if (! $process->isSuccessful()) {
            return [];
        }

        $payload = json_decode($process->getOutput(), true);

        return is_array($payload) ? $this->compactProbe($payload) : [];
    }

    public function resolveFfmpegPath(): ?string
    {
        $ffmpegPath = (string) config('downloads.ffmpeg_path', 'ffmpeg');
        if ($ffmpegPath === '') {
            return null;
        }

        if (is_dir($ffmpegPath)) {
            $ffmpegPath = rtrim($ffmpegPath, '\\/').DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'ffmpeg.exe' : 'ffmpeg');
        }

        return $ffmpegPath;
    }

    private function resolveFfprobePath(): ?string
    {
        $configured = (string) config('downloads.ffprobe_path', '');
        if ($configured !== '') {
            return $configured;
        }

        $ffmpeg = $this->resolveFfmpegPath();
        if ($ffmpeg === null) {
            return null;
        }

        $directory = dirname($ffmpeg);
        $candidate = $directory.DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'ffprobe.exe' : 'ffprobe');
        if (is_file($candidate)) {
            return $candidate;
        }

        return 'ffprobe';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function compactProbe(array $payload): array
    {
        $format = is_array($payload['format'] ?? null) ? $payload['format'] : [];
        $streams = is_array($payload['streams'] ?? null) ? $payload['streams'] : [];

        return [
            'format' => array_filter([
                'duration' => $this->numericString($format['duration'] ?? null),
                'bit_rate' => $this->numericString($format['bit_rate'] ?? null),
                'format_name' => is_string($format['format_name'] ?? null) ? $format['format_name'] : null,
                'format_long_name' => is_string($format['format_long_name'] ?? null) ? $format['format_long_name'] : null,
                'tags' => is_array($format['tags'] ?? null) ? $format['tags'] : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
            'streams' => array_values(array_map(function (array $stream): array {
                return array_filter([
                    'codec_type' => is_string($stream['codec_type'] ?? null) ? $stream['codec_type'] : null,
                    'codec_name' => is_string($stream['codec_name'] ?? null) ? $stream['codec_name'] : null,
                    'width' => is_numeric($stream['width'] ?? null) ? (int) $stream['width'] : null,
                    'height' => is_numeric($stream['height'] ?? null) ? (int) $stream['height'] : null,
                    'duration' => $this->numericString($stream['duration'] ?? null),
                    'bit_rate' => $this->numericString($stream['bit_rate'] ?? null),
                    'tags' => is_array($stream['tags'] ?? null) ? $stream['tags'] : null,
                ], static fn (mixed $value): bool => $value !== null && $value !== []);
            }, array_filter($streams, 'is_array'))),
        ];
    }

    private function numericString(mixed $value): int|float|null
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return (int) $number == $number ? (int) $number : $number;
    }
}
