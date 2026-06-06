import { onMounted, onUnmounted, type Ref } from 'vue';
import type { AudioDetail } from '@/types/audio';

type EchoChannel = {
    listen: (event: string, callback: (payload: unknown) => void) => EchoChannel;
};

type AudioFilesChangedPayload = {
    file_ids?: unknown;
    fileIds?: unknown;
};

type AudioPlaylistMembershipResponse = {
    playlist: string;
    files: Array<{
        id: number;
        is_member: boolean;
    }>;
};

type UseAudioPlaylistMembershipInvalidationOptions = {
    activePlaylistSlug: Ref<string>;
    audioIds: Ref<number[]>;
    detailsById: Ref<Record<number, AudioDetail>>;
    fetchAudioDetails: (ids: number[], force?: boolean) => Promise<void>;
    markPlaylistsStale: () => void;
};

export function useAudioPlaylistMembershipInvalidation(options: UseAudioPlaylistMembershipInvalidationOptions) {
    let echoChannelName: string | null = null;
    let membershipRequestToken = 0;

    function currentUserId(): number | null {
        const content = document.querySelector('meta[name="user-id"]')?.getAttribute('content');
        const parsed = Number(content);

        return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
    }

    function normalizeFileIds(value: unknown): number[] {
        if (!Array.isArray(value)) {
            return [];
        }

        return Array.from(new Set(value
            .map((id) => Number(id))
            .filter((id) => Number.isInteger(id) && id > 0)));
    }

    function payloadFileIds(payload: unknown): number[] {
        if (!payload || typeof payload !== 'object') {
            return [];
        }

        const typedPayload = payload as AudioFilesChangedPayload;

        return normalizeFileIds(typedPayload.file_ids ?? typedPayload.fileIds);
    }

    async function handleAudioFilesChanged(payload: unknown): Promise<void> {
        const visibleChangedIds = payloadFileIds(payload)
            .filter((id) => options.audioIds.value.includes(id));

        if (visibleChangedIds.length === 0) {
            return;
        }

        options.markPlaylistsStale();

        const requestToken = ++membershipRequestToken;
        const playlistSlug = options.activePlaylistSlug.value;

        try {
            const { data } = await window.axios.post<AudioPlaylistMembershipResponse>('/api/audio/playlists/membership', {
                playlist: playlistSlug,
                file_ids: visibleChangedIds,
            });

            if (requestToken !== membershipRequestToken || playlistSlug !== options.activePlaylistSlug.value) {
                return;
            }

            const memberIds = data.files
                .filter((file) => file.is_member)
                .map((file) => file.id);
            const memberIdLookup = new Set(memberIds);
            const removedIds = visibleChangedIds.filter((id) => !memberIdLookup.has(id));

            if (removedIds.length > 0) {
                const removedIdLookup = new Set(removedIds);
                options.audioIds.value = options.audioIds.value.filter((id) => !removedIdLookup.has(id));

                const nextDetails = { ...options.detailsById.value };
                for (const id of removedIds) {
                    delete nextDetails[id];
                }
                options.detailsById.value = nextDetails;
            }

            if (memberIds.length > 0) {
                await options.fetchAudioDetails(memberIds, true);
            }
        } catch (error) {
            console.error('Failed to refresh audio playlist membership:', error);
        }
    }

    function startEchoListener(): void {
        const userId = currentUserId();
        const echo = window.Echo as undefined | {
            private: (channel: string) => EchoChannel;
        };

        if (!echo || userId === null) {
            return;
        }

        echoChannelName = `App.Models.User.${userId}`;
        echo.private(echoChannelName).listen('.AudioFilesChanged', (payload: unknown) => {
            void handleAudioFilesChanged(payload);
        });
    }

    function stopEchoListener(): void {
        if (!echoChannelName) {
            return;
        }

        const echo = window.Echo as undefined | {
            leave: (channel: string) => void;
        };
        echo?.leave(echoChannelName);
        echoChannelName = null;
    }

    onMounted(() => {
        startEchoListener();
    });

    onUnmounted(() => {
        membershipRequestToken += 1;
        stopEchoListener();
    });

    return {
        handleAudioFilesChanged,
    };
}
