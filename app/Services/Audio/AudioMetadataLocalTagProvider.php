<?php

namespace App\Services\Audio;

use App\Models\File;

class AudioMetadataLocalTagProvider
{
    public function __construct(
        private readonly AudioMetadataValueExtractor $values,
    ) {}

    /**
     * @return array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}
     */
    public function candidate(File $file, bool $allowFilenameFallback = true): array
    {
        $payload = $this->values->metadataPayload($file);
        $values = [];
        $evidence = ['source' => 'embedded_tags'];

        $title = $this->values->firstStringForKeys($payload, ['title']);
        $artists = $this->values->extractNames($payload, ['artist', 'artists', 'album_artist', 'albumArtist', 'albumartist', 'performer']);
        $album = $this->values->firstStringForKeys($payload, ['album', 'albums']);
        $duration = $this->values->durationSeconds($file, $payload);
        $coverUrl = $this->values->firstStringForKeys($payload, ['cover_url', 'artwork_url', 'thumbnail_url']);
        $trackNumber = $this->firstTagNumber($payload, ['track', 'track_number', 'tracknumber']);
        $discNumber = $this->firstTagNumber($payload, ['disc', 'disc_number', 'discnumber', 'disk', 'disk_number']);
        $releaseLabel = $this->values->firstStringForKeys($payload, ['label', 'publisher', 'organization']);
        $catalogNumber = $this->values->firstStringForKeys($payload, ['catalog_number', 'catalognumber', 'catalogue_number', 'catalog']);
        $barcode = $this->values->firstStringForKeys($payload, ['barcode', 'upc']);
        $releaseDate = $this->values->firstStringForKeys($payload, ['date', 'year', 'originaldate', 'release_date']);
        $releaseCountry = $this->values->firstStringForKeys($payload, ['country', 'release_country']);
        $isrc = $this->values->firstStringForKeys($payload, ['isrc']);
        $musicBrainzRecordingId = $this->values->firstStringForKeys($payload, ['musicbrainz_recording_id', 'musicbrainz_trackid', 'musicbrainz_releasetrackid']);
        $musicBrainzReleaseId = $this->values->firstStringForKeys($payload, ['musicbrainz_release_id', 'musicbrainz_albumid']);
        $discogsReleaseId = $this->values->firstStringForKeys($payload, ['discogs_release_id', 'discogs_releaseid']);

        if ($allowFilenameFallback && $title === null && $artists === []) {
            $filenameCandidate = $this->values->filenameCandidate((string) $file->filename);
            if ($filenameCandidate !== null) {
                $title = $filenameCandidate['title'];
                $artists = [$filenameCandidate['artist']];
                $evidence['source'] = 'filename';
            }
        }

        $this->putIfPresent($values, 'title', $title);
        $this->putIfPresent($values, 'artists', $artists);
        $this->putIfPresent($values, 'album', $album);
        $this->putIfPresent($values, 'duration_seconds', $duration);
        $this->putIfPresent($values, 'cover_url', $coverUrl);
        $this->putIfPresent($values, 'track_number', $trackNumber);
        $this->putIfPresent($values, 'disc_number', $discNumber);
        $this->putIfPresent($values, 'release_label', $releaseLabel);
        $this->putIfPresent($values, 'catalog_number', $catalogNumber);
        $this->putIfPresent($values, 'barcode', $barcode);
        $this->putIfPresent($values, 'release_date', $releaseDate);
        $this->putIfPresent($values, 'release_country', $releaseCountry);
        $this->putIfPresent($values, 'isrc', $isrc);
        $this->putIfPresent($values, 'musicbrainz_recording_id', $musicBrainzRecordingId);
        $this->putIfPresent($values, 'musicbrainz_release_id', $musicBrainzReleaseId);
        $this->putIfPresent($values, 'discogs_release_id', $discogsReleaseId);

        return [
            'provider' => 'local',
            'confidence' => $this->confidence($values, (string) $evidence['source']),
            'values' => $values,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function confidence(array $values, string $source): int
    {
        if ($values === []) {
            return 0;
        }

        if ($source === 'filename') {
            return 45;
        }

        $confidence = 50;
        $confidence += array_key_exists('title', $values) ? 6 : 0;
        $confidence += array_key_exists('artists', $values) ? 6 : 0;
        $confidence += array_key_exists('album', $values) ? 4 : 0;
        $confidence += array_key_exists('duration_seconds', $values) ? 4 : 0;
        $confidence += array_key_exists('cover_url', $values) ? 3 : 0;
        $confidence += array_key_exists('track_number', $values) ? 2 : 0;
        $confidence += array_key_exists('release_label', $values) ? 2 : 0;
        $confidence += array_key_exists('catalog_number', $values) ? 2 : 0;

        return min(70, $confidence);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function firstTagNumber(array $payload, array $keys): ?string
    {
        $value = $this->values->firstStringForKeys($payload, $keys);
        if ($value === null) {
            return null;
        }

        $parts = preg_split('#\s*/\s*#', $value) ?: [];

        return $this->values->cleanString($parts[0] ?? $value);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function putIfPresent(array &$values, string $key, mixed $value): void
    {
        if ($value === null || $value === []) {
            return;
        }

        $values[$key] = $value;
    }
}
