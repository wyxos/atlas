<?php

namespace App\Services\Audio;

use App\Models\File;
use App\Models\Playlist;
use App\Services\Playlists\AudioPlaylistQueryService;
use App\Support\FileApiPath;

class AudioCoverResolver
{
    public function __construct(
        private readonly AudioPlaylistQueryService $playlistQuery,
    ) {}

    public function forFile(File $file): ?string
    {
        $albumCover = $file->albums
            ->map(fn ($album) => $album->defaultCover)
            ->filter()
            ->first();

        if ($albumCover) {
            return FileApiPath::albumCover((int) $albumCover->id);
        }

        if (is_string($file->poster_path) && trim($file->poster_path) !== '') {
            return FileApiPath::poster((int) $file->id);
        }

        if (is_string($file->preview_path) && trim($file->preview_path) !== '') {
            return FileApiPath::preview((int) $file->id);
        }

        $previewUrl = trim((string) ($file->preview_url ?? ''));

        return $previewUrl !== '' ? $previewUrl : null;
    }

    /**
     * @return array{cover_file_id: int|null, cover_file_ids: list<int>, cover_mode: string, cover_url: string|null}
     */
    public function forPlaylist(Playlist $playlist): array
    {
        $coverMode = $this->coverMode((string) $playlist->cover_mode);
        $coverFileIds = $this->normalizeCoverFileIds($playlist->cover_file_ids);

        $coverFileId = match ($coverMode) {
            'custom' => $this->customCoverFileId($coverFileIds),
            'random_track' => $this->playlistRandomCoverFileId($playlist),
            default => $this->playlistFirstCoverFileId($playlist),
        };

        $coverFile = $coverFileId !== null ? $this->coverFile($coverFileId) : null;

        return [
            'cover_file_id' => $coverFile?->id ? (int) $coverFile->id : null,
            'cover_file_ids' => $coverFileIds,
            'cover_mode' => $coverMode,
            'cover_url' => $coverFile ? $this->forFile($coverFile) : null,
        ];
    }

    private function coverMode(string $coverMode): string
    {
        return in_array($coverMode, ['custom', 'first_track', 'random_track'], true) ? $coverMode : 'first_track';
    }

    /**
     * @return list<int>
     */
    public function normalizeCoverFileIds(mixed $coverFileIds): array
    {
        if (! is_array($coverFileIds)) {
            return [];
        }

        $normalized = [];

        foreach ($coverFileIds as $coverFileId) {
            $coverFileId = (int) $coverFileId;
            if ($coverFileId <= 0 || in_array($coverFileId, $normalized, true)) {
                continue;
            }

            $normalized[] = $coverFileId;
        }

        return array_slice($normalized, 0, 24);
    }

    /**
     * @param  list<int>  $coverFileIds
     */
    private function customCoverFileId(array $coverFileIds): ?int
    {
        $validCoverFileIds = $this->validAudioFileIds($coverFileIds);
        if ($validCoverFileIds === []) {
            return null;
        }

        return $validCoverFileIds[random_int(0, count($validCoverFileIds) - 1)];
    }

    private function playlistFirstCoverFileId(Playlist $playlist): ?int
    {
        return $this->playlistQuery->firstFileIdForPlaylist($playlist);
    }

    private function playlistRandomCoverFileId(Playlist $playlist): ?int
    {
        return $this->playlistQuery->randomFileIdForPlaylist($playlist);
    }

    private function coverFile(int $fileId): ?File
    {
        return File::query()
            ->select(['id', 'preview_path', 'preview_url', 'poster_path'])
            ->whereKey($fileId)
            ->where('mime_type', 'like', 'audio/%')
            ->with(['albums:id', 'albums.defaultCover:id,album_id,path,mime_type'])
            ->first();
    }

    /**
     * @param  list<int>  $coverFileIds
     * @return list<int>
     */
    private function validAudioFileIds(array $coverFileIds): array
    {
        if ($coverFileIds === []) {
            return [];
        }

        $existingIds = File::query()
            ->whereIn('id', $coverFileIds)
            ->where('mime_type', 'like', 'audio/%')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $existingIds = array_flip($existingIds);

        return array_values(array_filter(
            $coverFileIds,
            static fn (int $coverFileId): bool => isset($existingIds[$coverFileId]),
        ));
    }
}
