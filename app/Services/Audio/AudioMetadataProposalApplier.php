<?php

namespace App\Services\Audio;

use App\Models\Album;
use App\Models\Artist;
use App\Models\AudioMetadataProposal;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AudioMetadataProposalApplier
{
    /**
     * @param  list<string>  $fields
     */
    public function apply(AudioMetadataProposal $proposal, User $user, array $fields = []): AudioMetadataProposal
    {
        if ($proposal->status !== 'pending') {
            abort(409, 'This metadata proposal has already been reviewed.');
        }

        $fields = $this->normalizeApplyFields($proposal, $fields);

        return DB::transaction(function () use ($proposal, $user, $fields): AudioMetadataProposal {
            $file = File::query()
                ->whereKey($proposal->file_id)
                ->lockForUpdate()
                ->firstOrFail();
            $file->load(['metadata', 'artists', 'albums']);

            $proposed = is_array($proposal->proposed_values) ? $proposal->proposed_values : [];
            $this->applyFileFields($file, $proposed, $fields);
            $this->applyRelationshipFields($file, $proposed, $fields);
            $this->applyMetadataFields($file, $proposed, $fields);

            $proposal->forceFill([
                'status' => 'applied',
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
                'applied_at' => now(),
            ])->save();

            return $proposal->fresh();
        });
    }

    public function ignore(AudioMetadataProposal $proposal, User $user): AudioMetadataProposal
    {
        if ($proposal->status !== 'pending') {
            abort(409, 'This metadata proposal has already been reviewed.');
        }

        $proposal->forceFill([
            'status' => 'ignored',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'ignored_at' => now(),
        ])->save();

        return $proposal->fresh();
    }

    /**
     * @param  array<string, mixed>  $proposed
     * @param  list<string>  $fields
     */
    private function applyFileFields(File $file, array $proposed, array $fields): void
    {
        if (in_array('title', $fields, true)) {
            $title = $this->cleanString($proposed['title'] ?? null);
            if ($title !== null) {
                $file->forceFill(['title' => $title]);
            }
        }

        if (in_array('cover_url', $fields, true)) {
            $coverUrl = $this->cleanString($proposed['cover_url'] ?? null);
            if ($coverUrl !== null) {
                $file->forceFill(['preview_url' => $coverUrl]);
            }
        }

        if (in_array('spotify_uri', $fields, true) && $this->isSpotifyFile($file)) {
            $spotifyId = $this->spotifyTrackId($this->cleanString($proposed['spotify_uri'] ?? null) ?? '');
            if ($spotifyId !== null) {
                $file->forceFill(['source_id' => $spotifyId]);
            }
        }

        $file->save();
    }

    /**
     * @param  array<string, mixed>  $proposed
     * @param  list<string>  $fields
     */
    private function applyRelationshipFields(File $file, array $proposed, array $fields): void
    {
        if (in_array('artists', $fields, true)) {
            $artists = $this->cleanStringList($proposed['artists'] ?? []);
            if ($artists !== []) {
                $this->syncArtists($file, $artists);
            }
        }

        if (in_array('album', $fields, true)) {
            $album = $this->cleanString($proposed['album'] ?? null);
            $freshFile = $file->fresh(['artists']);
            if ($album !== null && $freshFile instanceof File) {
                $this->syncAlbum($freshFile, $album);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $proposed
     * @param  list<string>  $fields
     */
    private function applyMetadataFields(File $file, array $proposed, array $fields): void
    {
        if (! in_array('duration_seconds', $fields, true)) {
            return;
        }

        $duration = $this->positiveInteger($proposed['duration_seconds'] ?? null);
        if ($duration !== null) {
            $this->mergeFileMetadata($file, ['duration_seconds' => $duration]);
        }
    }

    /**
     * @return list<string>
     */
    private function normalizeApplyFields(AudioMetadataProposal $proposal, array $fields): array
    {
        $changes = is_array($proposal->changes) ? array_keys($proposal->changes) : [];
        if ($fields === []) {
            return $changes;
        }

        return array_values(array_intersect($changes, $fields));
    }

    /**
     * @param  list<string>  $names
     */
    private function syncArtists(File $file, array $names): void
    {
        $artistIds = collect($names)
            ->map(fn (string $name): int => (int) Artist::query()->firstOrCreate([
                'normalized_name' => $this->normalizeName($name),
            ], [
                'name' => $name,
            ])->id)
            ->all();

        $file->artists()->sync($artistIds);
    }

    private function syncAlbum(File $file, string $name): void
    {
        $normalizedName = $this->normalizeName($name);
        $artistIds = $file->artists->pluck('id')->filter()->values();

        $album = null;
        if ($artistIds->isNotEmpty()) {
            $album = Album::query()
                ->where('normalized_name', $normalizedName)
                ->whereHas('files.artists', fn (Builder $query) => $query->whereKey($artistIds->all()))
                ->orderBy('id')
                ->first();
        }

        $album ??= Album::query()->create([
            'name' => $name,
            'normalized_name' => $normalizedName,
        ]);

        $file->albums()->sync([$album->id]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function mergeFileMetadata(File $file, array $payload): void
    {
        $metadata = FileMetadata::query()->firstOrNew(['file_id' => $file->id]);
        $current = is_array($metadata->payload) ? $metadata->payload : [];

        $metadata->payload = array_replace_recursive($current, $payload);
        $metadata->is_extracted = true;
        $metadata->save();
    }

    private function isSpotifyFile(File $file): bool
    {
        return mb_strtolower(trim((string) $file->source)) === 'spotify';
    }

    private function spotifyTrackId(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^spotify:track:([A-Za-z0-9]{22})$/', $value, $matches) === 1) {
            return $matches[1];
        }

        return preg_match('/^[A-Za-z0-9]{22}$/', $value) === 1 ? $value : null;
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $clean = preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';

        return $clean !== '' ? $clean : null;
    }

    /**
     * @return list<string>
     */
    private function cleanStringList(mixed $value): array
    {
        if (! is_array($value)) {
            $value = [$value];
        }

        $unique = [];
        foreach ($value as $entry) {
            $clean = $this->cleanString($entry);
            if ($clean !== null) {
                $unique[$this->normalizeName($clean)] = $clean;
            }
        }

        return array_values($unique);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) round((float) $value);

        return $value > 0 ? $value : null;
    }

    private function normalizeName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(trim($name))) ?? '');
    }
}
