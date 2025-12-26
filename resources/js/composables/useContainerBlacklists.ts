import { ref } from 'vue';
import type { ContainerBlacklist, CreateContainerBlacklistPayload } from '@/types/container-blacklist';

const blacklists = ref<ContainerBlacklist[]>([]);
const isLoading = ref(false);
const error = ref<string | null>(null);

function getErrorMessage(err: unknown): string | undefined {
    if (!err || typeof err !== 'object') {
        return undefined;
    }
    return (err as { response?: { data?: { message?: string } } }).response?.data?.message;
}

export function useContainerBlacklists() {
    /**
     * Fetch all blacklisted containers.
     */
    async function fetchBlacklists(): Promise<void> {
        isLoading.value = true;
        error.value = null;

        try {
            const response = await window.axios.get<ContainerBlacklist[]>('/api/container-blacklists');
            blacklists.value = response.data;
        } catch (err) {
            error.value = getErrorMessage(err) || 'Failed to fetch container blacklists';
            console.error('Failed to fetch container blacklists:', err);
        } finally {
            isLoading.value = false;
        }
    }

    /**
     * Create or update a container blacklist entry.
     */
    async function createBlacklist(
        containerId: number,
        actionType: ContainerBlacklist['action_type']
    ): Promise<ContainerBlacklist | null> {
        if (!actionType) {
            return null;
        }

        isLoading.value = true;
        error.value = null;

        try {
            const payload: CreateContainerBlacklistPayload = {
                container_id: containerId,
                action_type: actionType,
            };

            const response = await window.axios.post<ContainerBlacklist>('/api/container-blacklists', payload);
            const created = response.data;

            // Update or add to list
            const index = blacklists.value.findIndex((b) => b.id === created.id);
            if (index >= 0) {
                blacklists.value[index] = created;
            } else {
                blacklists.value.push(created);
            }

            return created;
        } catch (err) {
            error.value = getErrorMessage(err) || 'Failed to create container blacklist';
            console.error('Failed to create container blacklist:', err);
            return null;
        } finally {
            isLoading.value = false;
        }
    }

    /**
     * Remove a container from blacklist.
     */
    async function deleteBlacklist(containerId: number): Promise<boolean> {
        isLoading.value = true;
        error.value = null;

        try {
            await window.axios.delete(`/api/container-blacklists/${containerId}`);

            // Remove from list
            const index = blacklists.value.findIndex((b) => b.id === containerId);
            if (index >= 0) {
                blacklists.value.splice(index, 1);
            }

            return true;
        } catch (err) {
            error.value = getErrorMessage(err) || 'Failed to delete container blacklist';
            console.error('Failed to delete container blacklist:', err);
            return false;
        } finally {
            isLoading.value = false;
        }
    }

    /**
     * Check if a container is blacklisted.
     */
    async function checkBlacklist(containerId: number): Promise<{ blacklisted: boolean; blacklisted_at: string | null; action_type: string | null } | null> {
        try {
            const response = await window.axios.get<{ blacklisted: boolean; blacklisted_at: string | null; action_type: string | null }>(
                `/api/container-blacklists/${containerId}/check`
            );
            return response.data;
        } catch (err) {
            console.error('Failed to check container blacklist:', err);
            return null;
        }
    }

    /**
     * Check if a container is blacklisted (from local state).
     */
    function isContainerBlacklisted(containerId: number): boolean {
        const blacklist = blacklists.value.find((b) => b.id === containerId);
        return blacklist ? blacklist.blacklisted_at !== null : false;
    }

    return {
        blacklists,
        isLoading,
        error,
        fetchBlacklists,
        createBlacklist,
        deleteBlacklist,
        checkBlacklist,
        isContainerBlacklisted,
    };
}

