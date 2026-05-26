<?php

namespace App\Services\Playlists;

use App\Models\Playlist;
use App\Models\User;
use App\Services\Audio\AudioCoverResolver;
use Illuminate\Support\Collection;

class AudioPlaylistListingService
{
    public function __construct(
        private readonly AudioPlaylistQueryService $queryService,
        private readonly AudioCoverResolver $coverResolver,
    ) {}

    /**
     * @return array{sections: list<array{key: string, label: string, playlists: list<array<string, mixed>>}>}
     */
    public function forUser(User $user): array
    {
        $playlists = Playlist::query()
            ->where('user_id', $user->id)
            ->orderByRaw("CASE kind WHEN 'system' THEN 0 WHEN 'smart' THEN 1 ELSE 2 END")
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return [
            'sections' => [
                $this->section('system', 'System', $playlists),
                $this->section('smart', 'Smart', $playlists),
                $this->section('manual', 'Playlists', $playlists),
            ],
        ];
    }

    /**
     * @param  Collection<int, Playlist>  $playlists
     * @return array{key: string, label: string, playlists: list<array<string, mixed>>}
     */
    private function section(string $key, string $label, Collection $playlists): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'playlists' => $playlists
                ->where('kind', $key)
                ->values()
                ->map(fn (Playlist $playlist): array => $this->format($playlist))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function format(Playlist $playlist): array
    {
        $cover = $this->coverResolver->forPlaylist($playlist);

        return [
            'id' => (int) $playlist->id,
            'slug' => $playlist->slug,
            'name' => $playlist->name,
            'description' => $playlist->description,
            'kind' => $playlist->kind,
            'membership_mode' => $playlist->membership_mode,
            'source_key' => $playlist->source_key,
            'is_editable' => (bool) $playlist->is_editable,
            'is_deletable' => (bool) $playlist->is_deletable,
            'count' => $this->queryService->countForPlaylist($playlist),
            ...$cover,
        ];
    }
}
