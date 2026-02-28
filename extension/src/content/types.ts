export type MediaCandidatePayload = {
    candidate_id: string;
    type: 'media' | 'referrer';
    url: string;
};

export type MediaCandidate = {
    id: string;
    mediaUrl: string | null;
    anchorUrl: string | null;
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
