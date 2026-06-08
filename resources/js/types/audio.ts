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

export type AudioMetadataChange = {
    current: unknown;
    proposed: unknown;
};

export type AudioMetadataFieldOption = {
    id: string;
    provider: string;
    confidence: number;
    value: unknown;
    recommended: boolean;
    reason: string | null;
    review_verdict: string | null;
};

export type AudioMetadataProposal = {
    id: number;
    file_id: number;
    run_id: number;
    provider: string;
    status: 'pending' | 'applied' | 'ignored' | 'superseded' | string;
    confidence: number;
    current_values: Record<string, unknown>;
    proposed_values: Record<string, unknown>;
    changes: Record<string, AudioMetadataChange>;
    field_options?: Record<string, AudioMetadataFieldOption[]>;
    evidence: Record<string, unknown>;
    created_at: string | null;
    reviewed_at: string | null;
    applied_at: string | null;
    ignored_at: string | null;
};

export type AudioMetadataRun = {
    id: number;
    scope: string;
    source_filter: string;
    status: string;
    total_files: number;
    processed_files: number;
    proposal_count: number;
    failed_files: number;
    current_file_id: number | null;
    current_step: string | null;
    current_step_label: string | null;
    error: string | null;
    created_at: string | null;
    started_at: string | null;
    finished_at: string | null;
};

export type AudioMetadataRunResponse = {
    run: AudioMetadataRun;
    proposal?: AudioMetadataProposal | null;
};
