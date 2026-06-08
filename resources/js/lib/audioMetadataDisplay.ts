const METADATA_FIELD_ORDER = [
    'title',
    'artists',
    'album',
    'track_number',
    'disc_number',
    'duration_seconds',
    'release_label',
    'catalog_number',
    'barcode',
    'release_date',
    'release_country',
    'isrc',
    'musicbrainz_recording_id',
    'musicbrainz_release_id',
    'discogs_release_id',
    'cover_url',
    'spotify_uri',
];

export type AudioMetadataSourceLink = {
    key: string;
    label: string;
    url: string;
};

export function audioMetadataFieldLabel(field: string): string {
    return {
        title: 'Title',
        artists: 'Artists',
        album: 'Album',
        track_number: 'Track #',
        disc_number: 'Disc #',
        duration_seconds: 'Duration',
        release_label: 'Label',
        catalog_number: 'Catalog #',
        barcode: 'Barcode',
        release_date: 'Release date',
        release_country: 'Country',
        isrc: 'ISRC',
        musicbrainz_recording_id: 'MusicBrainz recording',
        musicbrainz_release_id: 'MusicBrainz release',
        discogs_release_id: 'Discogs release',
        cover_url: 'Cover',
        spotify_uri: 'Spotify URI',
    }[field] ?? field;
}

export function audioMetadataFieldOrder(field: string): number {
    const index = METADATA_FIELD_ORDER.indexOf(field);

    return index === -1 ? METADATA_FIELD_ORDER.length : index;
}

export function formatAudioMetadataValue(value: unknown): string {
    if (Array.isArray(value)) {
        return value.length > 0 ? value.join(', ') : 'None';
    }

    if (value === null || value === undefined || value === '') {
        return 'None';
    }

    if (typeof value === 'number') {
        return String(value);
    }

    return String(value);
}

export function isAudioMetadataCoverField(field: string): boolean {
    return field === 'cover_url';
}

export function audioMetadataCoverPreviewUrl(value: unknown): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();
    if (trimmed === '') {
        return null;
    }

    return trimmed.replace(/^http:\/\/coverartarchive\.org\//i, 'https://coverartarchive.org/');
}

export function audioMetadataProviderLabel(provider: string): string {
    if (provider === 'multi_source_review') {
        return 'Multi-source review';
    }

    if (provider === 'acoustid_musicbrainz') {
        return 'AcoustID / MusicBrainz';
    }

    if (provider === 'musicbrainz_cover_art') {
        return 'MusicBrainz Release';
    }

    if (provider === 'discogs_release') {
        return 'Discogs Release';
    }

    if (provider === 'musicbrainz_discogs') {
        return 'MusicBrainz / Discogs';
    }

    if (provider === 'acoustid_musicbrainz_discogs') {
        return 'AcoustID / MusicBrainz / Discogs';
    }

    if (provider === 'acoustid_musicbrainz_ai_discogs') {
        return 'AcoustID / MusicBrainz / AI / Discogs';
    }

    return provider
        .split(/[_\s-]+/)
        .filter(Boolean)
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

export function audioMetadataSourceLinks(evidence: Record<string, unknown>): AudioMetadataSourceLink[] {
    const links: AudioMetadataSourceLink[] = [];

    pushSourceLink(links, 'discogs-release', 'Discogs release', stringValue(evidence.discogs_release_url) ?? discogsReleaseUrl(evidence.discogs_release_id));
    pushSourceLink(links, 'discogs-master', 'Discogs master', stringValue(evidence.discogs_master_url) ?? discogsMasterUrl(evidence.discogs_master_id));
    pushSourceLink(links, 'musicbrainz-release', 'MusicBrainz release', musicBrainzReleaseUrl(evidence.musicbrainz_release_id));
    pushSourceLink(links, 'musicbrainz-recording', 'MusicBrainz recording', musicBrainzRecordingUrl(evidence.musicbrainz_recording_id));
    pushSourceLink(links, 'acoustid', 'AcoustID', acoustIdUrl(evidence.acoustid_id));
    pushSourceLink(links, 'vgmdb-album', 'VGMdb album', stringValue(evidence.vgmdb_album_link) ?? vgmdbAlbumUrl(evidence.vgmdb_album_id));

    if (evidence.cover_source === 'cover_art_archive') {
        pushSourceLink(links, 'cover-art-archive', 'Cover Art Archive', coverArtArchiveReleaseUrl(evidence.musicbrainz_release_id));
    }

    return links;
}

export function audioMetadataOptionSourceLabel(option: { provider: string; source_label?: string | null }): string {
    return stringValue(option.source_label) ?? audioMetadataProviderLabel(option.provider);
}

export function audioMetadataOptionSourceUrl(option: { source_url?: string | null }): string | null {
    return stringValue(option.source_url);
}

function pushSourceLink(links: AudioMetadataSourceLink[], key: string, label: string, url: string | null): void {
    if (url === null || links.some((link) => link.url === url)) {
        return;
    }

    links.push({ key, label, url });
}

function stringValue(value: unknown): string | null {
    if (typeof value !== 'string' && typeof value !== 'number') {
        return null;
    }

    const trimmed = String(value).trim();

    return trimmed !== '' ? trimmed : null;
}

function discogsReleaseUrl(value: unknown): string | null {
    const releaseId = stringValue(value);

    return releaseId !== null ? `https://www.discogs.com/release/${encodeURIComponent(releaseId)}` : null;
}

function discogsMasterUrl(value: unknown): string | null {
    const masterId = stringValue(value);

    return masterId !== null ? `https://www.discogs.com/master/${encodeURIComponent(masterId)}` : null;
}

function musicBrainzReleaseUrl(value: unknown): string | null {
    const releaseId = stringValue(value);

    return releaseId !== null ? `https://musicbrainz.org/release/${encodeURIComponent(releaseId)}` : null;
}

function musicBrainzRecordingUrl(value: unknown): string | null {
    const recordingId = stringValue(value);

    return recordingId !== null ? `https://musicbrainz.org/recording/${encodeURIComponent(recordingId)}` : null;
}

function acoustIdUrl(value: unknown): string | null {
    const acoustId = stringValue(value);

    return acoustId !== null ? `https://acoustid.org/track/${encodeURIComponent(acoustId)}` : null;
}

function coverArtArchiveReleaseUrl(value: unknown): string | null {
    const releaseId = stringValue(value);

    return releaseId !== null ? `https://coverartarchive.org/release/${encodeURIComponent(releaseId)}` : null;
}

function vgmdbAlbumUrl(value: unknown): string | null {
    const albumId = stringValue(value);

    return albumId !== null ? `https://vgmdb.net/album/${encodeURIComponent(albumId)}` : null;
}
