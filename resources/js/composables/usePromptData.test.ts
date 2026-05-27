import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { usePromptData } from './usePromptData';
import type { FeedItem } from './useTabs';

const mockAxios = {
    get: vi.fn(),
};

Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

function promptItem(id: number, metadata: FeedItem['metadata'] = null): FeedItem {
    return {
        id,
        width: 1,
        height: 1,
        page: 1,
        key: `1-${id}`,
        index: 0,
        src: `/files/${id}.jpg`,
        metadata,
    };
}

beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => {});
});

describe('usePromptData', () => {
    it('uses formatter-provided item metadata without loading file details', async () => {
        const items = ref<FeedItem[]>([
            promptItem(1, { prompt: 'formatted prompt' }),
        ]);

        const promptData = usePromptData(items);

        await expect(promptData.loadPromptData(items.value[0])).resolves.toBe('formatted prompt');
        expect(promptData.getPromptData(items.value[0])).toBe('formatted prompt');
        expect(mockAxios.get).not.toHaveBeenCalled();
    });

    it('loads API prompts using the moderation resolver order', async () => {
        mockAxios.get.mockResolvedValueOnce({
            data: {
                file: {
                    metadata: {
                        payload: {
                            meta: {
                                prompt: 'metadata meta prompt',
                            },
                        },
                    },
                    detail_metadata: {
                        prompt: 'detail prompt',
                    },
                    listing_metadata: {
                        meta: {
                            prompt: 'listing prompt',
                        },
                    },
                },
            },
        });

        const items = ref<FeedItem[]>([promptItem(2)]);
        const promptData = usePromptData(items);

        await expect(promptData.loadPromptData(items.value[0])).resolves.toBe('metadata meta prompt');
    });

    it('uses detail metadata prompts before listing metadata prompts', async () => {
        mockAxios.get.mockResolvedValueOnce({
            data: {
                file: {
                    metadata: {
                        payload: {},
                    },
                    detail_metadata: {
                        prompt: 'detail prompt',
                    },
                    listing_metadata: {
                        meta: {
                            prompt: 'listing prompt',
                            meta: {
                                prompt: 'nested listing prompt',
                            },
                        },
                    },
                },
            },
        });

        const items = ref<FeedItem[]>([promptItem(3)]);
        const promptData = usePromptData(items);

        await expect(promptData.loadPromptData(items.value[0])).resolves.toBe('detail prompt');
    });

    it('falls back to nested listing metadata prompts', async () => {
        mockAxios.get.mockResolvedValueOnce({
            data: {
                file: {
                    metadata: {
                        payload: {},
                    },
                    detail_metadata: {},
                    listing_metadata: {
                        meta: {
                            meta: {
                                prompt: 'nested listing prompt',
                            },
                        },
                    },
                },
            },
        });

        const items = ref<FeedItem[]>([promptItem(4)]);
        const promptData = usePromptData(items);

        await expect(promptData.loadPromptData(items.value[0])).resolves.toBe('nested listing prompt');
    });
});
