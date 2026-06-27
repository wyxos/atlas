<?php

namespace App\Services\DeviantArt;

use App\Support\DeviantArtMediaResolver;

trait InfersDeviantArtContainers
{
    /**
     * @param  list<mixed>  $candidateUrls
     * @return array<string, string>
     */
    public function containerMetadataFromCandidateUrls(array $candidateUrls): array
    {
        $metadata = [];

        foreach ($candidateUrls as $candidateUrl) {
            $post = $this->postFromUrl($candidateUrl);
            if ($post === null) {
                continue;
            }

            $metadata += $this->userContainerMetadata($post['username']);

            if ($post['has_file_index'] && ! isset($metadata['post_container_source_id'])) {
                $metadata += [
                    'post_container_referrer_url' => $post['referrer'],
                    'post_container_source' => self::SOURCE,
                    'post_container_source_id' => $post['source_id'],
                ];
            }
        }

        return $metadata;
    }

    public function postSourceIdFromUrl(mixed $url): ?string
    {
        return $this->postFromUrl($url)['source_id'] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function userContainerMetadata(string $username): array
    {
        $username = strtolower(trim($username));
        if ($username === '') {
            return [];
        }

        return [
            'user_container_referrer_url' => DeviantArtMediaResolver::artistGalleryUrl($username),
            'user_container_source' => self::SOURCE,
            'user_container_source_id' => $username,
        ];
    }

    /**
     * @return array{type: string, source: string, source_id: string, referrer: string}
     */
    private function userContainer(string $username): array
    {
        $metadata = $this->userContainerMetadata($username);

        return [
            'type' => 'User',
            'source' => self::SOURCE,
            'source_id' => $metadata['user_container_source_id'],
            'referrer' => $metadata['user_container_referrer_url'],
        ];
    }

    /**
     * @return array{username: string, source_id: string, referrer: string, has_file_index: bool}|null
     */
    private function postFromUrl(mixed $url): ?array
    {
        if (! is_string($url)) {
            return null;
        }

        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }

        $parts = parse_url($trimmed);
        if (! is_array($parts)) {
            return null;
        }

        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? strtolower($parts['scheme']) : null;
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = isset($parts['host']) && is_string($parts['host']) ? strtolower($parts['host']) : null;
        if (! in_array($host, ['deviantart.com', 'www.deviantart.com'], true)) {
            return null;
        }

        $path = isset($parts['path']) && is_string($parts['path']) ? trim($parts['path'], '/') : '';
        if ($path === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
        if (count($segments) !== 3 || strtolower($segments[1]) !== 'art') {
            return null;
        }

        $username = DeviantArtMediaResolver::artistUsername([
            'url' => 'https://www.deviantart.com/'.$segments[0].'/art/'.$segments[2],
        ]);
        $sourceId = rawurldecode($segments[2]);
        if ($username === null || $sourceId === '') {
            return null;
        }

        parse_str(isset($parts['query']) && is_string($parts['query']) ? $parts['query'] : '', $query);
        $fileIndex = $query['file'] ?? null;

        return [
            'username' => $username,
            'source_id' => $sourceId,
            'referrer' => 'https://www.deviantart.com/'.$username.'/art/'.$sourceId,
            'has_file_index' => is_string($fileIndex) && ctype_digit($fileIndex) && (int) $fileIndex > 0,
        ];
    }

    /**
     * @param  list<mixed>  $candidateUrls
     * @return array<string, string>
     */
    public function containerMetadataFromApiRow(array $row, array $candidateUrls): array
    {
        $metadata = $this->containerMetadataFromCandidateUrls($candidateUrls);
        $username = DeviantArtMediaResolver::artistUsername($row);

        if ($username !== null) {
            $metadata = [
                ...$metadata,
                ...$this->userContainerMetadata($username),
            ];
        }

        return $metadata;
    }
}
