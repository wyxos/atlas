import type { ReactionType } from './reaction';

export type AudioIdsResponse = {
    ids: number[];
    sources: Record<number, string | null>;
    source_ids: Record<number, string | null>;
    spotify_uris: Record<number, string | null>;
    cursor: {
        after_id: number;
        next_after_id: number | null;
        has_more: boolean;
        max_id: number;
    };
    pagination: {
        per_page: number;
        total: number | null;
        total_pages: number | null;
    };
};

export type AudioDetailsResponse = {
    items: Array<{
        id: number;
        title: string | null;
        source: string | null;
        source_id: string | null;
        spotify_uri: string | null;
        artists: string[];
        albums: string[];
        cover_url: string | null;
        duration_seconds: number | null;
        reaction: { type: ReactionType } | null;
        blacklisted_at: string | null;
        previewed_count: number;
        seen_count: number;
        play_count?: number;
        skip_count?: number;
    }>;
};

export type AudioDetail = Omit<AudioDetailsResponse['items'][number], 'id'>;

export type AudioSourceFilter = 'all' | 'spotify' | 'local';

export type AudioPlaylist = {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    kind: 'system' | 'smart' | 'manual';
    membership_mode: 'rules' | 'manual';
    source_key: string | null;
    is_editable: boolean;
    is_deletable: boolean;
    count: number;
    cover_mode: 'first_track' | 'custom' | 'random_track';
    cover_url: string | null;
    cover_file_id: number | null;
    cover_file_ids: number[];
};

export type AudioPlaylistSection = {
    key: 'system' | 'smart' | 'manual';
    label: string;
    playlists: AudioPlaylist[];
};

export type AudioPlaylistsResponse = {
    sections: AudioPlaylistSection[];
};
