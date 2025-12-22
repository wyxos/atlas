import { ref, computed } from 'vue';
import type { MasonryItem } from './useTabs';

/**
 * Composable for managing prompt data loading, caching, and dialog state.
 */
export function usePromptData(items: import('vue').Ref<MasonryItem[]>) {
    const promptDataLoading = ref<Map<number, boolean>>(new Map());
    const promptDataCache = ref<Map<number, string>>(new Map());
    const promptDialogOpen = ref<boolean>(false);
    const promptDialogItemId = ref<number | null>(null);

    // Load prompt data for an item (from metadata or API)
    async function loadPromptData(item: MasonryItem): Promise<string | null> {
        // Check cache first
        if (promptDataCache.value.has(item.id)) {
            return promptDataCache.value.get(item.id) ?? null;
        }

        // Check if prompt is already in metadata
        const metadata = (item as any).metadata;
        if (metadata?.prompt) {
            const prompt = String(metadata.prompt);
            promptDataCache.value.set(item.id, prompt);
            return prompt;
        }

        // Load from API if not in metadata
        if (promptDataLoading.value.get(item.id)) {
            return null; // Already loading
        }

        promptDataLoading.value.set(item.id, true);
        try {
            const response = await window.axios.get(`/api/files/${item.id}`);
            const file = response.data?.file;
            // Check metadata payload (JSON) or detail_metadata
            const metadataPayload = file?.metadata?.payload;
            const prompt = (typeof metadataPayload === 'object' && metadataPayload?.prompt)
                ? String(metadataPayload.prompt)
                : (file?.detail_metadata?.prompt ? String(file.detail_metadata.prompt) : null);
            if (prompt) {
                promptDataCache.value.set(item.id, prompt);
                return prompt;
            }
            return null;
        } catch (error) {
            console.error('Failed to load prompt data:', error);
            return null;
        } finally {
            promptDataLoading.value.set(item.id, false);
        }
    }

    // Get prompt data for display (from cache or metadata)
    function getPromptData(item: MasonryItem): string | null {
        const metadata = (item as any).metadata;
        return promptDataCache.value.get(item.id) || metadata?.prompt || null;
    }

    // Get current prompt dialog item
    const currentPromptItem = computed(() => {
        if (promptDialogItemId.value === null) return null;
        return items.value.find(item => item.id === promptDialogItemId.value) || null;
    });

    // Get prompt data for current dialog item
    const currentPromptData = computed(() => {
        const item = currentPromptItem.value;
        if (!item) return null;
        return getPromptData(item);
    });

    // Open prompt dialog for an item
    async function openPromptDialog(item: MasonryItem): Promise<void> {
        promptDialogItemId.value = item.id;
        promptDialogOpen.value = true;
        // Load prompt data if not already loaded
        if (!getPromptData(item) && !promptDataLoading.value.get(item.id)) {
            await loadPromptData(item);
        }
    }

    // Close prompt dialog
    function closePromptDialog(): void {
        promptDialogOpen.value = false;
        // Keep itemId for a moment to allow dialog to close smoothly
        setTimeout(() => {
            promptDialogItemId.value = null;
        }, 200);
    }

    // Copy prompt to clipboard
    async function copyPromptToClipboard(prompt: string): Promise<void> {
        try {
            await navigator.clipboard.writeText(prompt);
        } catch (error) {
            console.error('Failed to copy prompt:', error);
        }
    }

    return {
        promptDataLoading,
        promptDataCache,
        promptDialogOpen,
        promptDialogItemId,
        loadPromptData,
        getPromptData,
        currentPromptItem,
        currentPromptData,
        openPromptDialog,
        closePromptDialog,
        copyPromptToClipboard,
    };
}

