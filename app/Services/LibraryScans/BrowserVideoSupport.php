<?php

namespace App\Services\LibraryScans;

use App\Support\FileMimeType;

class BrowserVideoSupport
{
    /**
     * Returns true only when Atlas needs a generated streamable copy.
     */
    public function shouldCreateStreamableVideo(?string $mimeType, array $probe): bool
    {
        $mimeType = FileMimeType::canonicalize($mimeType);

        return is_string($mimeType)
            && str_starts_with($mimeType, 'video/')
            && ! $this->isBrowserSupported($mimeType, $probe);
    }

    public function isBrowserSupported(?string $mimeType, array $probe): bool
    {
        $mimeType = FileMimeType::canonicalize($mimeType);
        $videoStream = $this->firstStreamOfType($probe, 'video');

        if (! $videoStream) {
            return false;
        }

        $videoCodec = strtolower((string) ($videoStream['codec_name'] ?? ''));

        return match ($mimeType) {
            'video/mp4' => in_array($videoCodec, ['h264', 'avc1'], true)
                && $this->hasSupportedAudioCodec($probe, ['aac', 'mp3', 'mp4a']),
            'video/webm' => in_array($videoCodec, ['vp8', 'vp9', 'av1'], true)
                && $this->hasSupportedAudioCodec($probe, ['opus', 'vorbis']),
            default => false,
        };
    }

    private function hasSupportedAudioCodec(array $probe, array $supportedCodecs): bool
    {
        $audioStream = $this->firstStreamOfType($probe, 'audio');

        if (! $audioStream) {
            return true;
        }

        $audioCodec = strtolower((string) ($audioStream['codec_name'] ?? ''));

        return $audioCodec !== '' && in_array($audioCodec, $supportedCodecs, true);
    }

    private function firstStreamOfType(array $probe, string $type): ?array
    {
        foreach (($probe['streams'] ?? []) as $stream) {
            if (($stream['codec_type'] ?? null) === $type) {
                return is_array($stream) ? $stream : null;
            }
        }

        return null;
    }
}
