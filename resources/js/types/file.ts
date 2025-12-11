export interface File {
    id: number;
    source: string;
    source_id: string | null;
    filename: string;
    ext: string | null;
    size: number | null;
    mime_type: string | null;
    hash: string | null;
    title: string | null;
    description: string | null;
    url: string | null;
    file_url?: string | null;
    referrer_url: string | null;
    path: string | null;
    absolute_path: string | null;
    thumbnail_url: string | null;
    thumbnail_path: string | null;
    tags: string[] | null;
    parent_id: number | null;
    chapter: string | null;
    previewed_at: string | null;
    previewed_count: number;
    seen_at: string | null;
    seen_count: number;
    blacklisted_at: string | null;
    blacklist_reason: string | null;
    downloaded: boolean;
    downloaded_at: string | null;
    download_progress: number;
    not_found: boolean;
    listing_metadata: Record<string, unknown> | null;
    detail_metadata: Record<string, unknown> | null;
    created_at: string;
    updated_at: string;
}

