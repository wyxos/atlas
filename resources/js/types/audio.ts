import type { ReactionType } from './reaction';

export type AudioIdsResponse = {
    ids: number[];
    sources: Record<number, string | null>;
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
        artists: string[];
        albums: string[];
        cover_url: string | null;
        duration_seconds: number | null;
        reaction: { type: ReactionType } | null;
        blacklisted_at: string | null;
        previewed_count: number;
        seen_count: number;
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
};

export type AudioPlaylistSection = {
    key: 'system' | 'smart' | 'manual';
    label: string;
    playlists: AudioPlaylist[];
};

export type AudioPlaylistsResponse = {
    sections: AudioPlaylistSection[];
};
