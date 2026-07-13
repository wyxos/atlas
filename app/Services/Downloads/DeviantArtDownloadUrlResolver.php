<?php

namespace App\Services\Downloads;

use App\Models\File;
use App\Models\User;
use App\Services\DeviantArt\DeviantArtOAuthService;
use App\Services\DeviantArtImages;
use App\Support\DeviantArtApiClient;
use Carbon\CarbonImmutable;
use Throwable;

final class DeviantArtDownloadUrlResolver implements SourceDownloadUrlResolver
{
    public function __construct(
        private readonly DeviantArtOAuthService $oauth,
        private readonly DeviantArtApiClient $client,
    ) {}

    public function supports(File $file): bool
    {
        return strtolower(trim((string) $file->source)) === DeviantArtImages::SOURCE
            && ! $this->isKnownUndownloadable($file);
    }

    public function resolve(File $file, array $runtimeContext = []): ?ResolvedDownloadUrl
    {
        if (! $this->supports($file)) {
            return null;
        }

        $deviationId = $this->deviationId($file);
        $userId = $this->userId($runtimeContext);
        if ($deviationId === null || $userId === null) {
            return null;
        }

        $user = User::query()->find($userId);
        if (! $user) {
            return null;
        }

        try {
            $token = $this->oauth->getValidAccessToken($user);
            if (! is_string($token) || trim($token) === '') {
                return null;
            }

            $payload = $this->client->downloadPayload($deviationId, $token);
        } catch (Throwable) {
            return null;
        }

        $url = isset($payload['src']) && is_string($payload['src']) ? trim($payload['src']) : '';
        if ($url === '' || ! preg_match('/^https?:\/\//i', $url)) {
            return null;
        }

        return new ResolvedDownloadUrl(
            url: $url,
            filename: isset($payload['filename']) && is_string($payload['filename']) ? trim($payload['filename']) : null,
            filesize: isset($payload['filesize']) && is_numeric($payload['filesize']) ? (int) $payload['filesize'] : null,
            expiresAt: $this->urlExpiry($url),
            providerResolved: true,
        );
    }

    private function urlExpiry(string $url): ?CarbonImmutable
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (! is_string($query) || $query === '') {
            return null;
        }

        parse_str($query, $parameters);
        $token = $parameters['token'] ?? null;
        if (! is_string($token) || $token === '') {
            return null;
        }

        $segments = explode('.', $token);
        if (count($segments) < 2) {
            return null;
        }

        $payload = strtr($segments[1], '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $decoded = base64_decode($payload, true);
        if (! is_string($decoded)) {
            return null;
        }

        $claims = json_decode($decoded, true);
        $expiresAt = is_array($claims) ? ($claims['exp'] ?? null) : null;
        if (! is_numeric($expiresAt) || (int) $expiresAt <= 0) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC((int) $expiresAt);
    }

    private function isKnownUndownloadable(File $file): bool
    {
        $listingMetadata = is_array($file->listing_metadata) ? $file->listing_metadata : [];
        $isDownloadable = $listingMetadata['is_downloadable'] ?? null;

        return $isDownloadable === false || $isDownloadable === 0 || $isDownloadable === '0';
    }

    private function deviationId(File $file): ?string
    {
        $listingMetadata = is_array($file->listing_metadata) ? $file->listing_metadata : [];

        foreach ([$listingMetadata['deviationid'] ?? null, $file->source_id] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $runtimeContext
     */
    private function userId(array $runtimeContext): ?int
    {
        $userId = $runtimeContext['user_id'] ?? null;

        return is_numeric($userId) && (int) $userId > 0 ? (int) $userId : null;
    }
}
