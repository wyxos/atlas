<?php

namespace App\Services\Audio;

class AudioAlbumFolderMatcher
{
    public function directory(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '') {
            return null;
        }

        $directory = trim(dirname($path), '. /');

        return $directory !== '' ? $directory : null;
    }

    public function looksLikeAlbum(string $directory, string $album): bool
    {
        $directoryTokens = $this->tokens(basename($directory));
        if (count($directoryTokens) < 2) {
            return false;
        }

        $albumTokens = array_flip($this->tokens($album));

        foreach ($directoryTokens as $token) {
            if (! isset($albumTokens[$token])) {
                return false;
            }
        }

        return true;
    }

    public function likePathPrefix(string $directory): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $directory);

        return $escaped.'/%';
    }

    /**
     * @return list<string>
     */
    private function tokens(string $value): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($value)) ?: [];

        return array_values(array_filter($parts, fn (string $part): bool => $part !== ''));
    }
}
