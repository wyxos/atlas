<?php

namespace App\Services\Audio;

use App\Models\File;
use App\Models\FileMetadata;

class AudioMetadataCanonicalPayloadWriter
{
    /**
     * @param  array<string, mixed>  $proposed
     * @param  list<string>  $fields
     */
    public function apply(File $file, array $proposed, array $fields): void
    {
        $audio = [];

        foreach ([
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
        ] as $field) {
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

        $this->merge($file, ['audio' => $audio]);
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
