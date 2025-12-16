export type ContainerBlacklistActionType = 'ui_countdown' | 'auto_dislike' | 'blacklist';

export interface ContainerBlacklist {
    id: number;
    type: string;
    source: string;
    source_id: string;
    referrer?: string | null;
    action_type: ContainerBlacklistActionType | null;
    blacklisted_at: string | null;
    created_at?: string;
    updated_at?: string;
}

export interface CreateContainerBlacklistPayload {
    container_id: number;
    action_type: ContainerBlacklistActionType;
}

