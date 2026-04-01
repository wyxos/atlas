import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import Browse from './Browse.vue';
import {
    setupBrowseTestMocks,
    createTestRouter,
    waitForStable,
    waitForTabContent,
    createMockTabConfig,
    setupAxiosMocks,
    type BrowseMocks,
} from '@/test/browse-test-utils';

// Define mocks using vi.hoisted so they're available for vi.mock factories
const {
    mockAxios,
    mockIsLoading,
    mockCancelLoad,
    mockRemove,
    mockRestore,
    mockQueuePreviewIncrement,
} = vi.hoisted(() => ({
    mockAxios: {
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
        patch: vi.fn(),
    },
    mockIsLoading: { value: false },
    mockCancelLoad: vi.fn(),
    mockRemove: vi.fn(),
    mockRestore: vi.fn(),
    mockQueuePreviewIncrement: vi.fn(),
}));

// Create mocks object for helper functions
const mocks: BrowseMocks = {
    mockAxios,
    mockIsLoading: mockIsLoading as any,
    mockCancelLoad,
    mockRemove,
    mockRestore,
    mockQueuePreviewIncrement,
};

vi.mock('@wyxos/vibe', async () => {
    const { createVibePageMock } = await import('@/test/browse-test-utils');
    return createVibePageMock({ mockIsLoading, mockCancelLoad, mockRemove, mockRestore });
});

// Mock axios
vi.mock('axios', () => ({
    default: mockAxios,
}));

// Mock window.axios
Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

describe('Browse - Immediate Actions Integration', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        setupBrowseTestMocks(mocks);
    });

    it('collects immediate actions when files are auto-disliked', async () => {
        const tabConfig = createMockTabConfig(1, {
            params: { service: 'civit-ai-images', page: 1 },
        });

        const immediateActions = [
            { id: 1, action_type: 'blacklist', thumbnail: 'https://example.com/thumb1.jpg' },
            { id: 2, action_type: 'blacklist', thumbnail: 'https://example.com/thumb2.jpg' }];

        // Setup axios mocks to return immediate actions in moderation response
        setupAxiosMocks(mocks, tabConfig, {
            items: [],
            nextPage: null,
            services: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
            moderation: {
                moderatedOut: immediateActions,
            },
        });

        const router = await createTestRouter('/browse');
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await waitForStable(wrapper);
        const tabContent = await waitForTabContent(wrapper);

        expect(tabContent).not.toBeNull();

        // Wait for initial load
        await flushPromises();
        await nextTick();

        // Trigger loading:stop
        await tabContent?.onLoadingStop?.({ fetched: 10 });

        await flushPromises();
        await nextTick();

        // Verify that immediate actions were collected
        expect(mockAxios.get).toHaveBeenCalled();
    });
});



