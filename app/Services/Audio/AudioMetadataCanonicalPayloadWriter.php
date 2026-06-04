<?php

namespace App\Services\Audio;

use App\Models\File;
use App\Models\FileMetadata;

class AudioMetadataCanonicalPayloadWriter
{
    private const STRING_FIELDS = [
        'title',
        'album',
        'track_number',
        'disc_number',
        'release_label',
        'catalog_number',
        'barcode',
        'release_date',
        'release_country',
        'isrc',
        'musicbrainz_recording_id',
        'musicbrainz_release_id',
        'discogs_release_id',
        'spotify_uri',
        'cover_url',
    ];

    private const RAW_KEYS_BY_FIELD = [
        'title' => ['title'],
        'artists' => ['artist', 'artists', 'album_artist', 'albumArtist', 'albumartist', 'performer'],
        'album' => ['album', 'albums'],
        'duration_seconds' => ['duration_seconds', 'duration'],
        'cover_url' => ['cover_url', 'artwork_url', 'thumbnail_url'],
        'track_number' => ['track', 'track_number', 'tracknumber'],
        'disc_number' => ['disc', 'disc_number', 'discnumber', 'disk', 'disk_number'],
        'release_label' => ['label', 'publisher', 'organization', 'release_label'],
        'catalog_number' => ['catalog_number', 'catalognumber', 'catalogue_number', 'catalog'],
        'barcode' => ['barcode', 'upc'],
        'release_date' => ['date', 'year', 'originaldate', 'release_date'],
        'release_country' => ['country', 'release_country'],
        'isrc' => ['isrc'],
        'musicbrainz_recording_id' => ['musicbrainz_recording_id', 'musicbrainz_trackid', 'musicbrainz_releasetrackid'],
        'musicbrainz_release_id' => ['musicbrainz_release_id', 'musicbrainz_albumid'],
        'discogs_release_id' => ['discogs_release_id', 'discogs_releaseid'],
        'spotify_uri' => ['spotify_uri'],
    ];

    /**
     * @param  array<string, mixed>  $proposed
     * @param  list<string>  $fields
     */
    public function apply(File $file, array $proposed, array $fields): void
    {
        $audio = [];

        foreach (self::STRING_FIELDS as $field) {
            if (! in_array($field, $fields, true)) {
                continue;
            }

            $value = $this->cleanString($proposed[$field] ?? null);
            if ($value !== null) {
                $audio[$field] = $value;
            }
        }

        if (in_array('duration_seconds', $fields, true)) {
            $duration = $this->positiveInteger($proposed['duration_seconds'] ?? null);
            if ($duration !== null) {
                $audio['duration_seconds'] = $duration;
            }
        }

        if (in_array('artists', $fields, true)) {
            $artists = $this->cleanStringList($proposed['artists'] ?? []);
            if ($artists !== []) {
                $audio['artists'] = $artists;
            }
        }

        $aliases = [];
        foreach ([
            'title_aliases' => 'title',
            'artist_aliases' => 'artists',
            'album_aliases' => 'album',
        ] as $field => $aliasKey) {
            if (! in_array($field, $fields, true)) {
                continue;
            }

            $values = $this->cleanStringList($proposed[$field] ?? []);
            if ($values !== []) {
                $aliases[$aliasKey] = $values;
            }
        }

        if ($aliases !== []) {
            $audio['aliases'] = $aliases;
        }

        if ($audio === []) {
            return;
        }

        $this->replaceCanonicalAudio($file, $audio, $fields);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function merge(File $file, array $payload): void
    {
        $metadata = FileMetadata::query()->firstOrNew(['file_id' => $file->id]);
        $current = is_array($metadata->payload) ? $metadata->payload : [];

        $metadata->payload = array_replace_recursive($current, $payload);
        $metadata->is_extracted = true;
        $metadata->save();
    }

    /**
     * @param  array<string, mixed>  $audio
     * @param  list<string>  $fields
     */
    private function replaceCanonicalAudio(File $file, array $audio, array $fields): void
    {
        $metadata = FileMetadata::query()->firstOrNew(['file_id' => $file->id]);
        $payload = is_array($metadata->payload) ? $metadata->payload : [];
        $existingAudio = data_get($payload, 'audio');
        $nextAudio = array_replace_recursive(is_array($existingAudio) ? $existingAudio : [], $audio);

        $payload = $this->withoutRawAudioKeys($payload, $fields);
        $payload = array_replace_recursive($payload, $this->canonicalRootPayload($nextAudio));
        $payload['audio'] = $nextAudio;

        $metadata->payload = $payload;
        $metadata->is_extracted = true;
        $metadata->save();
    }

    /**
     * @param  array<string, mixed>  $audio
     * @return array<string, mixed>
     */
    private function canonicalRootPayload(array $audio): array
    {
        $payload = [];

        foreach (self::STRING_FIELDS as $field) {
            $value = $this->cleanString($audio[$field] ?? null);
            if ($value !== null) {
                $payload[$field] = $value;
            }
        }

        $duration = $this->positiveInteger($audio['duration_seconds'] ?? null);
        if ($duration !== null) {
            $payload['duration_seconds'] = $duration;
        }

        $artists = $this->cleanStringList($audio['artists'] ?? []);
        if ($artists !== []) {
            $payload['artists'] = $artists;
            $payload['artist'] = implode('; ', $artists);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function withoutRawAudioKeys(array $payload, array $fields): array
    {
        $keys = $this->rawKeysForFields($fields);
        if ($keys === []) {
            return $payload;
        }

        $payload = $this->withoutKeys($payload, $keys);
        $tags = data_get($payload, 'probe.format.tags');
        if (is_array($tags)) {
            data_set($payload, 'probe.format.tags', $this->withoutKeys($tags, $keys));
        }

        return $payload;
    }

    /**
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function rawKeysForFields(array $fields): array
    {
        $keys = [];
        foreach ($fields as $field) {
            $keys = [
                ...$keys,
                ...(self::RAW_KEYS_BY_FIELD[$field] ?? []),
            ];
        }

        return array_values(array_unique(array_map(
            fn (string $key): string => $this->normalizeKey($key),
            $keys
        )));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $normalizedKeys
     * @return array<string, mixed>
     */
    private function withoutKeys(array $payload, array $normalizedKeys): array
    {
        foreach (array_keys($payload) as $key) {
            if (in_array($this->normalizeKey((string) $key), $normalizedKeys, true)) {
                unset($payload[$key]);
            }
        }

        return $payload;
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

    private function normalizeKey(string $key): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($key)) ?? '';
    }
}
