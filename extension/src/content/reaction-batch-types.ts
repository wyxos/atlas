export type BatchReactionItem = {
    candidateId: string;
    url: string;
    referrerUrlHashAware: string;
    pageUrl: string;
    tagName: 'img' | 'video' | 'iframe';
};

export type ListingMetadataResourceContainer = {
    type: 'Checkpoint' | 'LoRA';
    modelId: number;
    modelVersionId: number;
    referrerUrl: string;
};

export type ListingMetadataOverrides = {
    postId?: number;
    username?: string;
    resource_containers?: ListingMetadataResourceContainer[];
};
