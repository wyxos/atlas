import { ref, computed } from 'vue';
import type { FeedItem } from './useTabs';

/**
 * Composable for managing prompt data loading, caching, and dialog state.
 */
export function usePromptData(items: import('vue').Ref<FeedItem[]>) {
    const promptDataLoading = ref<Map<number, boolean>>(new Map());
    const promptDataCache = ref<Map<number, string>>(new Map());
    const promptDialogOpen = ref<boolean>(false);
    const promptDialogItemId = ref<number | null>(null);
    type PromptNode = {
        prompt?: unknown;
        meta?: PromptNode | null;
    };

    type FilePromptPayload = {
        metadata?: {
            payload?: PromptNode | null;
        } | null;
        detail_metadata?: PromptNode | null;
        listing_metadata?: PromptNode | null;
    };

    function hasPromptNode(value: unknown): value is PromptNode {
        return typeof value === 'object' && value !== null;
    }

    function filledPrompt(value: unknown): string | null {
        return typeof value === 'string' && value !== '' ? value : null;
    }

    function extractPrompt(metadata?: PromptNode | null): string | null {
        if (!metadata || typeof metadata !== 'object') {
            return null;
        }

        return filledPrompt(metadata.prompt)
            ?? (hasPromptNode(metadata.meta) ? filledPrompt(metadata.meta.prompt) : null);
    }

    function extractListingPrompt(metadata?: PromptNode | null): string | null {
        if (!hasPromptNode(metadata) || !hasPromptNode(metadata.meta)) {
            return null;
        }

        return filledPrompt(metadata.meta.prompt)
            ?? (hasPromptNode(metadata.meta.meta) ? filledPrompt(metadata.meta.meta.prompt) : null);
    }

    function extractFilePrompt(file?: FilePromptPayload | null): string | null {
        if (!file) {
            return null;
        }

        return extractPrompt(file.metadata?.payload)
            ?? filledPrompt(file.detail_metadata?.prompt)
            ?? extractListingPrompt(file.listing_metadata);
    }

    // Load prompt data for an item (from metadata or API)
    async function loadPromptData(item: FeedItem): Promise<string | null> {
        // Check cache first
        if (promptDataCache.value.has(item.id)) {
            return promptDataCache.value.get(item.id) ?? null;
        }

        // Check if prompt is already in metadata
        const metadata = item.metadata as PromptNode | undefined;
        const cachedPrompt = extractPrompt(metadata);
        if (cachedPrompt) {
            promptDataCache.value.set(item.id, cachedPrompt);
            return cachedPrompt;
        }

        // Load from API if not in metadata
        if (promptDataLoading.value.get(item.id)) {
            return null; // Already loading
        }

        promptDataLoading.value.set(item.id, true);
        try {
            const { data } = await window.axios.get(`/api/files/${item.id}`);
            const file = data?.file as FilePromptPayload | undefined;
            const prompt = extractFilePrompt(file);
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
    function getPromptData(item: FeedItem): string | null {
        const metadata = item.metadata as PromptNode | undefined;
        return promptDataCache.value.get(item.id) || extractPrompt(metadata);
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
    async function openPromptDialog(item: FeedItem): Promise<void> {
        promptDialogItemId.value = item.id;
        promptDialogOpen.value = true;
        // Load prompt data if not already loaded
        if (!getPromptData(item) && !promptDataLoading.value.get(item.id)) {
            await loadPromptData(item);
        }
    }

    async function selectPromptItem(item: FeedItem): Promise<void> {
        promptDialogItemId.value = item.id;

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

    function clearPromptSelection(): void {
        promptDialogOpen.value = false;
        promptDialogItemId.value = null;
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
        selectPromptItem,
        closePromptDialog,
        clearPromptSelection,
        copyPromptToClipboard,
    };
}
