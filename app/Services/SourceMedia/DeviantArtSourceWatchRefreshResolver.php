<?php

namespace App\Services\SourceMedia;

use App\Models\Container;
use App\Models\File;
use App\Models\User;
use App\Services\DeviantArt\DeviantArtOAuthService;
use App\Services\DeviantArtImages;
use App\Support\DeviantArtApiClient;
use App\Support\DeviantArtMediaResolver;
use Illuminate\Support\Collection;

final class DeviantArtSourceWatchRefreshResolver implements SourceWatchRefreshResolver
{
    public function __construct(
        private readonly DeviantArtOAuthService $oauth,
        private readonly DeviantArtApiClient $client,
    ) {}

    public function supports(File $file): bool
    {
        return $this->isDeviantArtFile($file);
    }

    public function watch(File $file, User $user): bool
    {
        $username = $this->username($file, allowQuery: true);
        if ($username === null) {
            return false;
        }

        $token = $this->oauth->getValidAccessToken($user);
        if (! is_string($token) || trim($token) === '') {
            return false;
        }

        return $this->client->watchUser($username, $token);
    }

    public function unwatch(File $file, User $user): bool
    {
        $username = $this->username($file, allowQuery: true);
        if ($username === null) {
            return false;
        }

        $token = $this->oauth->getValidAccessToken($user);
        if (! is_string($token) || trim($token) === '') {
            return false;
        }

        $unwatched = $this->client->unwatchUser($username, $token);
        if ($unwatched) {
            $this->markWatcherAccess($file, false);
        }

        return $unwatched;
    }

    private function isDeviantArtFile(File $file): bool
    {
        return strtolower(trim((string) $file->source)) === DeviantArtImages::SOURCE;
    }

    private function username(File $file, bool $allowQuery): ?string
    {
        $listingMetadata = is_array($file->listing_metadata) ? $file->listing_metadata : [];
        if (! isset($listingMetadata['referrer_url']) && is_string($file->referrer_url)) {
            $listingMetadata['referrer_url'] = $file->referrer_url;
        }

        $username = DeviantArtMediaResolver::artistUsername($listingMetadata);
        if ($username !== null) {
            return $username;
        }

        $containers = $this->containers($file, $allowQuery);
        foreach ($containers as $container) {
            $username = $this->usernameFromContainer($container);
            if ($username !== null) {
                return $username;
            }
        }

        return null;
    }

    /**
     * @return iterable<Container>
     */
    private function containers(File $file, bool $allowQuery): iterable
    {
        if ($file->relationLoaded('containers')) {
            $containers = $file->getRelation('containers');

            return $containers instanceof Collection ? $containers : [];
        }

        if (! $allowQuery) {
            return [];
        }

        return $file->containers()
            ->where('source', DeviantArtImages::SOURCE)
            ->whereRaw('lower(type) = ?', ['user'])
            ->get();
    }

    private function usernameFromContainer(Container $container): ?string
    {
        if (strtolower(trim((string) $container->source)) !== DeviantArtImages::SOURCE) {
            return null;
        }

        if (strtolower(trim((string) $container->type)) !== 'user') {
            return null;
        }

        return DeviantArtMediaResolver::artistUsername([
            'user_container_source_id' => $container->source_id,
            'referrer_url' => $container->referrer,
        ]);
    }

    private function markWatcherAccess(File $file, bool $hasAccess): void
    {
        $listingMetadata = is_array($file->listing_metadata) ? $file->listing_metadata : [];
        $premiumFolderData = data_get($listingMetadata, 'premium_folder_data');
        if (! is_array($premiumFolderData)) {
            return;
        }

        if (strtolower(trim((string) ($premiumFolderData['type'] ?? ''))) !== 'watchers') {
            return;
        }

        data_set($listingMetadata, 'premium_folder_data.has_access', $hasAccess);
        $file->forceFill([
            'listing_metadata' => $listingMetadata,
        ])->save();
    }
}
