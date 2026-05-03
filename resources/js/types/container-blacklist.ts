import type { ModerationRuleActionType, ModerationRuleBlacklistPreviewedCountMode } from './moderation';

// Re-export for backward compatibility
export type ContainerBlacklistActionType = ModerationRuleActionType;
export type ContainerBlacklistPreviewedCountMode = ModerationRuleBlacklistPreviewedCountMode;

export interface ContainerBlacklist {
    id: number;
    type: string;
    source: string;
    source_id: string;
    referrer?: string | null;
    action_type: ContainerBlacklistActionType | null;
    blacklist_previewed_count_mode: ContainerBlacklistPreviewedCountMode;
    blacklisted_at: string | null;
    blacklisted_files_count?: number;
    created_at?: string;
    updated_at?: string;
}

export interface ContainerBlacklistFileStats {
    unreacted: number;
    blacklisted: number;
    positive: number;
}

export interface ContainerBlacklistStatus {
    blacklisted: boolean;
    blacklisted_at: string | null;
    action_type: ContainerBlacklistActionType | null;
    blacklist_previewed_count_mode: ContainerBlacklistPreviewedCountMode;
    file_stats: ContainerBlacklistFileStats;
}

export interface CreateContainerBlacklistPayload {
    container_id: number;
    action_type: ContainerBlacklistActionType;
    blacklist_previewed_count_mode?: ContainerBlacklistPreviewedCountMode;
}
