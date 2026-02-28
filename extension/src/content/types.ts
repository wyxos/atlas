export type MediaCandidatePayload = {
    id: string;
    media_url: string | null;
    anchor_url: string | null;
    page_url: string;
};

export type MediaCandidate = {
    payload: MediaCandidatePayload;
    element: HTMLImageElement | HTMLVideoElement;
};

export type ExtensionMatchResult = {
    id: string;
    exists: boolean;
    reaction: string | null;
    reacted_at: string | null;
    downloaded_at: string | null;
    blacklisted_at: string | null;
};
