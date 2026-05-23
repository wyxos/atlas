<?php

namespace App\Services\Playlists;

use App\Models\Playlist;
use App\Services\FilePreviewService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AudioPlaylistQueryService
{
    public function baseAudioQuery(): Builder
    {
        return DB::table('files')
            ->where('mime_type', 'like', 'audio/%');
    }

    public function applySlug(Builder $query, int $userId, ?string $slug): Builder
    {
        $slug = trim((string) $slug);
        if ($slug === '' || $slug === 'all') {
            return $query;
        }

        $playlist = DB::table('playlists')
            ->where('user_id', $userId)
            ->where('slug', $slug)
            ->first();

        if (! $playlist) {
            return $query->whereRaw('1 = 0');
        }

        return $this->applyPlaylistData(
            $query,
            (string) $playlist->membership_mode,
            $playlist->membership_rules,
            (int) $playlist->id,
            $userId,
        );
    }

    public function countForPlaylist(Playlist $playlist): int
    {
        $query = $this->baseAudioQuery();
        $this->applyPlaylistData(
            $query,
            (string) $playlist->membership_mode,
            $playlist->membership_rules,
            (int) $playlist->id,
            (int) $playlist->user_id,
        );

        return (int) $query->count();
    }

    private function applyPlaylistData(
        Builder $query,
        string $membershipMode,
        mixed $membershipRules,
        int $playlistId,
        int $userId,
    ): Builder {
        if ($membershipMode === 'manual') {
            return $query->whereExists(function (Builder $subQuery) use ($playlistId): void {
                $subQuery
                    ->selectRaw('1')
                    ->from('file_playlist')
                    ->whereColumn('file_playlist.file_id', 'files.id')
                    ->where('file_playlist.playlist_id', $playlistId);
            });
        }

        return $this->applyRules($query, $this->normalizeRules($membershipRules), $userId);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeRules(mixed $membershipRules): array
    {
        if (is_array($membershipRules)) {
            return $membershipRules;
        }

        if (! is_string($membershipRules) || trim($membershipRules) === '') {
            return [];
        }

        $decoded = json_decode($membershipRules, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function applyRules(Builder $query, array $rules, int $userId): Builder
    {
        return match ((string) ($rules['operator'] ?? 'all')) {
            'blacklisted' => $this->applyBlacklistedRule($query),
            'imported' => $this->applyImportedRule($query),
            'missing_album' => $this->applyMissingAlbumRule($query),
            'missing_album_cover' => $this->applyMissingAlbumCoverRule($query),
            'missing_artist' => $this->applyMissingArtistRule($query),
            'online' => $query
                ->whereNull('imported_at')
                ->whereNotNull('source')
                ->whereRaw('LOWER(TRIM(source)) NOT IN (?, ?)', ['local', 'nas']),
            'reaction' => $this->applyReactionRule($query, $userId, (string) ($rules['type'] ?? '')),
            'reaction_any' => $this->applyReactionAnyRule($query, $userId, $rules['types'] ?? []),
            'source' => $this->applySourceRule($query, (string) ($rules['source_key'] ?? '')),
            'unreacted' => $this->applyUnreactedRule($query, $userId),
            default => $query,
        };
    }

    private function applyImportedRule(Builder $query): Builder
    {
        return $query->where(function (Builder $nested): void {
            $nested
                ->whereNotNull('imported_at')
                ->orWhereRaw('LOWER(TRIM(source)) IN (?, ?)', ['local', 'nas']);
        });
    }

    private function applyMissingArtistRule(Builder $query): Builder
    {
        $this->applyImportedRule($query);

        return $query->whereNotExists(function (Builder $subQuery): void {
            $subQuery
                ->selectRaw('1')
                ->from('artist_file')
                ->whereColumn('artist_file.file_id', 'files.id');
        });
    }

    private function applyMissingAlbumRule(Builder $query): Builder
    {
        $this->applyImportedRule($query);

        return $query->whereNotExists(function (Builder $subQuery): void {
            $subQuery
                ->selectRaw('1')
                ->from('album_file')
                ->whereColumn('album_file.file_id', 'files.id');
        });
    }

    private function applyMissingAlbumCoverRule(Builder $query): Builder
    {
        $this->applyImportedRule($query);

        return $query
            ->whereExists(function (Builder $subQuery): void {
                $subQuery
                    ->selectRaw('1')
                    ->from('album_file')
                    ->whereColumn('album_file.file_id', 'files.id');
            })
            ->whereNotExists(function (Builder $subQuery): void {
                $subQuery
                    ->selectRaw('1')
                    ->from('album_file')
                    ->join('album_covers', 'album_covers.album_id', '=', 'album_file.album_id')
                    ->whereColumn('album_file.file_id', 'files.id')
                    ->where('album_covers.is_default', true);
            });
    }

    private function applyBlacklistedRule(Builder $query): Builder
    {
        return $query->where(function (Builder $nested): void {
            $nested
                ->whereNotNull('blacklisted_at')
                ->orWhere('previewed_count', '>=', FilePreviewService::FEED_REMOVED_PREVIEW_COUNT);
        });
    }

    private function applyReactionRule(Builder $query, int $userId, string $type): Builder
    {
        if (! in_array($type, ['love', 'like', 'funny'], true)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereExists(function (Builder $subQuery) use ($type, $userId): void {
            $subQuery
                ->selectRaw('1')
                ->from('reactions')
                ->whereColumn('reactions.file_id', 'files.id')
                ->where('reactions.user_id', $userId)
                ->where('reactions.type', $type);
        });
    }

    private function applyReactionAnyRule(Builder $query, int $userId, mixed $types): Builder
    {
        if (! is_array($types)) {
            return $query->whereRaw('1 = 0');
        }

        $types = array_values(array_unique(array_filter(
            array_map(static fn (mixed $type): string => is_string($type) ? trim($type) : '', $types),
            static fn (string $type): bool => in_array($type, ['love', 'like', 'funny'], true),
        )));

        if ($types === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereExists(function (Builder $subQuery) use ($types, $userId): void {
            $subQuery
                ->selectRaw('1')
                ->from('reactions')
                ->whereColumn('reactions.file_id', 'files.id')
                ->where('reactions.user_id', $userId)
                ->whereIn('reactions.type', $types);
        });
    }

    private function applyUnreactedRule(Builder $query, int $userId): Builder
    {
        return $query->whereNotExists(function (Builder $subQuery) use ($userId): void {
            $subQuery
                ->selectRaw('1')
                ->from('reactions')
                ->whereColumn('reactions.file_id', 'files.id')
                ->where('reactions.user_id', $userId);
        });
    }

    private function applySourceRule(Builder $query, string $sourceKey): Builder
    {
        $sourceKey = strtolower(trim($sourceKey));
        if ($sourceKey === '') {
            return $query->whereRaw('1 = 0');
        }

        if ($sourceKey === 'local') {
            return $query->whereRaw('LOWER(TRIM(source)) IN (?, ?)', ['local', 'nas']);
        }

        return $query->whereRaw('LOWER(TRIM(source)) = ?', [$sourceKey]);
    }
}
