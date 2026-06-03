import { computed, ref, type ComputedRef, type Ref } from 'vue';
import { DEFAULT_ATLAS_DOMAIN, getStoredConnectionOptions } from '../atlas-options';
import type { BadgeMatchResult } from './reaction-check-queue';
import { deleteBadgeFile } from './reaction-file-delete';
import { emptyMatchResult } from './reaction-badge-utils';

type ReactionBadgeFileStateOptions = {
    matchResult: Ref<BadgeMatchResult>;
    trackedFileId: Ref<number | null>;
    trackedTransferId: Ref<number | null>;
    transferStatus: Ref<string | null>;
    progressPercent: Ref<number | null>;
    hasSeenActiveTransfer: Ref<boolean>;
    isActive: () => boolean;
    persistCurrentBadgeState: (isLocked: boolean) => void;
};

type ReactionBadgeFileState = {
    isDeletingFile: Ref<boolean>;
    canDeleteFile: ComputedRef<boolean>;
    atlasFileUrl: ComputedRef<string | null>;
    syncAtlasDomain: () => Promise<void>;
    handleDeleteFileClick: () => Promise<void>;
};

export function useReactionBadgeFileState(options: ReactionBadgeFileStateOptions): ReactionBadgeFileState {
    const atlasDomain = ref(DEFAULT_ATLAS_DOMAIN);
    const isDeletingFile = ref(false);
    const canDeleteFile = computed(() => options.matchResult.value.exists && options.trackedFileId.value !== null);
    const atlasFileUrl = computed(() => {
        if (options.trackedFileId.value === null || options.matchResult.value.downloadedAt === null) {
            return null;
        }

        return `${atlasDomain.value.replace(/\/+$/, '')}/browse/file/${options.trackedFileId.value}`;
    });

    async function syncAtlasDomain(): Promise<void> {
        try {
            const stored = await getStoredConnectionOptions();
            if (options.isActive()) {
                atlasDomain.value = stored.atlasDomain;
            }
        } catch {
            return;
        }
    }

    async function handleDeleteFileClick(): Promise<void> {
        const fileId = options.trackedFileId.value;
        if (!canDeleteFile.value || fileId === null) {
            return;
        }

        isDeletingFile.value = true;
        try {
            const result = await deleteBadgeFile(fileId);
            if (!options.isActive() || !result.ok) {
                return;
            }

            options.matchResult.value = emptyMatchResult();
            options.trackedFileId.value = null;
            options.trackedTransferId.value = null;
            options.transferStatus.value = null;
            options.progressPercent.value = null;
            options.hasSeenActiveTransfer.value = false;
            options.persistCurrentBadgeState(false);
        } finally {
            if (options.isActive()) {
                isDeletingFile.value = false;
            }
        }
    }

    return {
        atlasFileUrl,
        canDeleteFile,
        handleDeleteFileClick,
        isDeletingFile,
        syncAtlasDomain,
    };
}
