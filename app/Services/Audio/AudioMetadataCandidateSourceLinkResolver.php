<?php

namespace App\Services\Audio;

class AudioMetadataCandidateSourceLinkResolver
{
    public function __construct(
        private readonly AudioMetadataValueExtractor $values,
    ) {}

    /**
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     * @return array{label:string|null,url:string|null}
     */
    public function forCandidate(array $candidate): array
    {
        $evidence = $candidate['evidence'] ?? [];

        if (($url = $this->sourceUrl($evidence['discogs_release_url'] ?? null)) !== null) {
            return ['label' => 'Discogs release', 'url' => $url];
        }

        if (($releaseId = $this->values->cleanString($evidence['discogs_release_id'] ?? null)) !== null) {
            return ['label' => 'Discogs release', 'url' => 'https://www.discogs.com/release/'.$releaseId];
        }

        if (($url = $this->sourceUrl($evidence['discogs_master_url'] ?? null)) !== null) {
            return ['label' => 'Discogs master', 'url' => $url];
        }

        if (($releaseId = $this->values->cleanString($evidence['musicbrainz_release_id'] ?? null)) !== null) {
            return ['label' => 'MusicBrainz release', 'url' => 'https://musicbrainz.org/release/'.$releaseId];
        }

        if (($recordingId = $this->values->cleanString($evidence['musicbrainz_recording_id'] ?? null)) !== null) {
            return ['label' => 'MusicBrainz recording', 'url' => 'https://musicbrainz.org/recording/'.$recordingId];
        }

        if (($acoustId = $this->values->cleanString($evidence['acoustid_id'] ?? null)) !== null) {
            return ['label' => 'AcoustID', 'url' => 'https://acoustid.org/track/'.$acoustId];
        }

        if (($url = $this->sourceUrl($evidence['vgmdb_album_link'] ?? null)) !== null) {
            return ['label' => 'VGMdb album', 'url' => $url];
        }

        if (($albumId = $this->values->cleanString($evidence['vgmdb_album_id'] ?? null)) !== null) {
            return ['label' => 'VGMdb album', 'url' => 'https://vgmdb.net/album/'.$albumId];
        }

        if (($url = $this->sourceUrl($evidence['spotify_track_url'] ?? null) ?? $this->spotifyTrackUrl($evidence['spotify_track_id'] ?? null)) !== null) {
            return ['label' => 'Spotify track', 'url' => $url];
        }

        if (($url = $this->sourceUrl($evidence['apple_track_url'] ?? null)) !== null) {
            return ['label' => 'Apple Music track', 'url' => $url];
        }

        if (($url = $this->sourceUrl($evidence['apple_collection_url'] ?? null)) !== null) {
            return ['label' => 'Apple Music album', 'url' => $url];
        }

        if (($url = $this->sourceUrl($evidence['deezer_track_url'] ?? null) ?? $this->deezerUrl('track', $evidence['deezer_track_id'] ?? null)) !== null) {
            return ['label' => 'Deezer track', 'url' => $url];
        }

        if (($url = $this->deezerUrl('album', $evidence['deezer_album_id'] ?? null)) !== null) {
            return ['label' => 'Deezer album', 'url' => $url];
        }

        return ['label' => null, 'url' => null];
    }

    private function sourceUrl(mixed $value): ?string
    {
        $url = $this->values->cleanString($value);
        if ($url === null || ! str_starts_with($url, 'http')) {
            return null;
        }

        return $url;
    }

    private function spotifyTrackUrl(mixed $value): ?string
    {
        $trackId = $this->values->cleanString($value);

        return $trackId !== null ? 'https://open.spotify.com/track/'.$trackId : null;
    }

    private function deezerUrl(string $type, mixed $value): ?string
    {
        $id = $this->values->cleanString($value);

        return $id !== null ? 'https://www.deezer.com/'.$type.'/'.$id : null;
    }
}
