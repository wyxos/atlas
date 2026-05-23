<?php

namespace App\Services\Playlists;

use Illuminate\Support\Str;

class SystemPlaylistCatalog
{
    /**
     * @param  list<string>  $sourceKeys
     * @return list<array{
     *     slug: string,
     *     name: string,
     *     description: string,
     *     membership_rules: array<string, mixed>,
     *     source_key: string|null,
     *     sort_order: int
     * }>
     */
    public function definitions(array $sourceKeys): array
    {
        $definitions = [
            [
                'slug' => 'all',
                'name' => 'All audio',
                'description' => 'Every audio file',
                'membership_rules' => ['operator' => 'all'],
                'source_key' => null,
                'sort_order' => 10,
            ],
            [
                'slug' => 'favorites',
                'name' => 'Favorites',
                'description' => 'Files marked as favorites',
                'membership_rules' => ['operator' => 'reaction', 'type' => 'love'],
                'source_key' => null,
                'sort_order' => 20,
            ],
            [
                'slug' => 'likes',
                'name' => 'Likes',
                'description' => 'Files marked as liked',
                'membership_rules' => ['operator' => 'reaction', 'type' => 'like'],
                'source_key' => null,
                'sort_order' => 30,
            ],
            [
                'slug' => 'favorites-and-likes',
                'name' => 'Favorites & Likes',
                'description' => 'Files marked as favorites or liked',
                'membership_rules' => ['operator' => 'reaction_any', 'types' => ['love', 'like']],
                'source_key' => null,
                'sort_order' => 35,
            ],
            [
                'slug' => 'funny',
                'name' => 'Funny',
                'description' => 'Files marked as funny',
                'membership_rules' => ['operator' => 'reaction', 'type' => 'funny'],
                'source_key' => null,
                'sort_order' => 40,
            ],
            [
                'slug' => 'reacted',
                'name' => 'Reacted',
                'description' => 'Files marked as favorites, liked, or funny',
                'membership_rules' => ['operator' => 'reaction_any', 'types' => ['love', 'like', 'funny']],
                'source_key' => null,
                'sort_order' => 45,
            ],
            [
                'slug' => 'unreacted',
                'name' => 'Unreacted',
                'description' => 'No reaction yet',
                'membership_rules' => ['operator' => 'unreacted'],
                'source_key' => null,
                'sort_order' => 50,
            ],
            [
                'slug' => 'banned',
                'name' => 'Banned',
                'description' => 'Files marked as banned',
                'membership_rules' => ['operator' => 'blacklisted'],
                'source_key' => null,
                'sort_order' => 60,
            ],
            [
                'slug' => 'imports',
                'name' => 'Imports',
                'description' => 'Files saved in Atlas',
                'membership_rules' => ['operator' => 'imported'],
                'source_key' => null,
                'sort_order' => 70,
            ],
            [
                'slug' => 'online-sources',
                'name' => 'Online sources',
                'description' => 'Files from online sources',
                'membership_rules' => ['operator' => 'online'],
                'source_key' => null,
                'sort_order' => 80,
            ],
            [
                'slug' => 'no-artist',
                'name' => 'No artist',
                'description' => 'Imported audio without artist relationships',
                'membership_rules' => ['operator' => 'missing_artist'],
                'source_key' => null,
                'sort_order' => 90,
            ],
            [
                'slug' => 'no-album',
                'name' => 'No album',
                'description' => 'Imported audio without album relationships',
                'membership_rules' => ['operator' => 'missing_album'],
                'source_key' => null,
                'sort_order' => 100,
            ],
            [
                'slug' => 'no-album-cover',
                'name' => 'No album cover',
                'description' => 'Imported audio linked to albums without default covers',
                'membership_rules' => ['operator' => 'missing_album_cover'],
                'source_key' => null,
                'sort_order' => 105,
            ],
        ];

        foreach ($this->normalizeSourceKeys($sourceKeys) as $index => $sourceKey) {
            $definitions[] = [
                'slug' => 'source-'.Str::slug($sourceKey),
                'name' => $this->sourceLabel($sourceKey),
                'description' => 'Source: '.$this->sourceLabel($sourceKey),
                'membership_rules' => ['operator' => 'source', 'source_key' => $sourceKey],
                'source_key' => $sourceKey,
                'sort_order' => 110 + $index,
            ];
        }

        return $definitions;
    }

    /**
     * @param  list<string>  $sourceKeys
     * @return list<string>
     */
    private function normalizeSourceKeys(array $sourceKeys): array
    {
        $normalized = array_map(
            fn (string $sourceKey): string => $this->normalizeSourceKey($sourceKey),
            ['spotify', ...$sourceKeys],
        );
        $normalized = array_filter($normalized, fn (string $sourceKey): bool => ! in_array($sourceKey, ['', 'local'], true));

        return array_values(array_unique($normalized));
    }

    private function normalizeSourceKey(string $sourceKey): string
    {
        $sourceKey = strtolower(trim($sourceKey));

        return match ($sourceKey) {
            'nas' => 'local',
            default => $sourceKey,
        };
    }

    private function sourceLabel(string $sourceKey): string
    {
        return match ($sourceKey) {
            'civitai' => 'CivitAI',
            'local' => 'Library files',
            'spotify' => 'Spotify',
            'wallhaven' => 'Wallhaven',
            'youtube' => 'YouTube',
            default => Str::of($sourceKey)
                ->replace(['-', '_'], ' ')
                ->title()
                ->toString(),
        };
    }
}
