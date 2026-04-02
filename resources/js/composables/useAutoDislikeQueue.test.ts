import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { useAutoDislikeQueue } from './useAutoDislikeQueue';
import { queueManager } from './useQueue';
import type { FeedItem } from './useTabs';

function createItem(id: number): FeedItem {
    return {
        id,
        width: 500,
        height: 500,
        page: 1,
        key: `1-${id}`,
        index: id - 1,
        src: `https://example.com/preview${id}.jpg`,
        preview: `https://example.com/preview${id}.jpg`,
        type: 'image',
    } as FeedItem;
}

const mockAxiosPost = vi.fn();

Object.defineProperty(window, 'axios', {
    value: {
        post: mockAxiosPost,
    },
    writable: true,
});

function createSubject(overrides: Parameters<typeof useAutoDislikeQueue>[0] = {
    items: ref<FeedItem[]>([]),
    masonry: ref(null),
    isLocal: ref(false),
}) {
    return useAutoDislikeQueue({
        items: overrides.items,
        masonry: overrides.masonry,
        isLocal: overrides.isLocal,
        matchesActiveLocalFilters: overrides.matchesActiveLocalFilters,
    });
}

function getCountdownItem(fileId: number) {
    return queueManager.collection.getAll().find((item) => item.id === `auto-dislike-${fileId}`);
}

describe('useAutoDislikeQueue', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        queueManager.collection.reset();
        mockAxiosPost.mockReset();
        mockAxiosPost.mockResolvedValue({
            data: {
                file_ids: [],
            },
        });
    });

    afterEach(() => {
        queueManager.collection.reset();
        vi.useRealTimers();
    });

    it('pauses existing auto-dislike countdowns while the container drawer is open', () => {
        const autoDislikeQueue = createSubject();

        autoDislikeQueue.startAutoDislikeCountdown(101, createItem(101));
        expect(getCountdownItem(101)?.isPaused).toBe(false);

        autoDislikeQueue.freezeAutoDislikeOnly('container-drawer');
        expect(getCountdownItem(101)?.isPaused).toBe(true);

        autoDislikeQueue.unfreezeAutoDislikeOnly('container-drawer');
        vi.advanceTimersByTime(1999);
        expect(getCountdownItem(101)?.isPaused).toBe(true);

        vi.advanceTimersByTime(1);
        expect(getCountdownItem(101)?.isPaused).toBe(false);
    });

    it('starts new auto-dislike countdowns paused while the container drawer remains open', () => {
        const autoDislikeQueue = createSubject();

        autoDislikeQueue.freezeAutoDislikeOnly('container-drawer');
        autoDislikeQueue.startAutoDislikeCountdown(202, createItem(202));

        expect(getCountdownItem(202)?.isPaused).toBe(true);
    });

    it('keeps countdowns paused until every pause source has released them', () => {
        const autoDislikeQueue = createSubject();

        autoDislikeQueue.startAutoDislikeCountdown(303, createItem(303));
        autoDislikeQueue.freezeAutoDislikeOnly('file-viewer');
        autoDislikeQueue.freezeAutoDislikeOnly('container-drawer');

        autoDislikeQueue.unfreezeAutoDislikeOnly('container-drawer');
        vi.advanceTimersByTime(2000);
        expect(getCountdownItem(303)?.isPaused).toBe(true);

        autoDislikeQueue.unfreezeAutoDislikeOnly('file-viewer');
        vi.advanceTimersByTime(2000);
        expect(getCountdownItem(303)?.isPaused).toBe(false);
    });

    it('removes auto-disliked local items that no longer match the active filters after countdown completion', async () => {
        const item = createItem(404);
        const items = ref([item]);
        const masonry = ref({
            remove: vi.fn().mockResolvedValue(undefined),
        } as any);
        mockAxiosPost.mockResolvedValueOnce({
            data: {
                file_ids: [404],
            },
        });

        const autoDislikeQueue = createSubject({
            items,
            masonry,
            isLocal: ref(true),
            matchesActiveLocalFilters: () => false,
        });

        autoDislikeQueue.startAutoDislikeCountdown(404, item);

        await vi.runAllTimersAsync();

        expect(mockAxiosPost).toHaveBeenCalledWith(
            expect.stringContaining('/api/files/auto-dislike/batch'),
            {
                file_ids: [404],
            },
        );
        expect(item.reaction).toEqual({ type: 'dislike' });
        expect(item.auto_disliked).toBe(true);
        expect(item.will_auto_dislike).toBe(false);
        expect(masonry.value.remove).toHaveBeenCalledWith([item]);
    });
});
